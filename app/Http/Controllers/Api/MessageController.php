<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageCreated;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AI\AIRouter;
use App\Services\Memory\MemoryService;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    private const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente personal inteligente, empático y proactivo llamado AI Companion. Tu misión es conocer profundamente al usuario y ser su apoyo en todos los aspectos de su vida diaria.

**Tus capacidades principales:**
- Conocer y recordar los gustos, preferencias, alergias, rutinas, relaciones y vida cotidiana del usuario
- Hacer recomendaciones personalizadas basadas en su perfil: restaurantes, hoteles, destinos de viaje, actividades de ocio
- Planificar: viajes completos (vuelos, hoteles, itinerarios), eventos, citas, presupuestos, compras
- Apoyar en tareas diarias: redacción de mensajes y emails, análisis de situaciones, toma de decisiones
- Dar consejos prácticos de salud, finanzas, productividad y bienestar adaptados al perfil del usuario

**Tu forma de ser:**
- Sé proactivo: anticípate a las necesidades del usuario y ofrece sugerencias antes de que las pida
- Usa un tono amigable, cálido y natural, como un asistente de confianza de toda la vida
- Si el usuario menciona un viaje, recomienda destinos, hoteles, restaurantes y actividades alineados con sus gustos
- Si el usuario tiene alergias, restricciones alimentarias u otras condiciones, tenlas siempre en cuenta
- Si no tienes información suficiente del usuario, pregunta de forma natural para conocerlo mejor
- Responde en el idioma que use el usuario (principalmente español)
- Sé conciso en respuestas conversacionales, pero detallado cuando el usuario necesite información específica
- Recuerda siempre el contexto de la conversación para dar respuestas coherentes y personalizadas
PROMPT;

    public function __construct(
        private AIRouter $router,
        private MemoryService $memory,
        private PushNotificationService $push,
    ) {}

    public function send(Request $request, Conversation $conversation): JsonResponse|StreamedResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'content'  => 'required|string',
            'provider' => 'nullable|string',
            'stream'   => 'nullable|boolean',
        ]);

        $user = $request->user();

        // Save user message
        $userMessage = $conversation->messages()->create([
            'user_id' => $user->id,
            'role'    => 'user',
            'content' => $data['content'],
        ]);

        // Build message history for the AI
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        // Inject memory context as system prompt
        $settings = $user->setting;
        $systemPrompt = self::DEFAULT_SYSTEM_PROMPT;

        if ($settings?->persona) {
            $systemPrompt .= "\n\n" . ($settings->persona['prompt'] ?? '');
        }

        if ($settings?->memory_enabled) {
            $memoryContext = $this->memory->buildContextPrompt($user, $data['content']);
            if ($memoryContext) {
                $systemPrompt .= "\n\n" . $memoryContext;
            }
        }

        array_unshift($history, ['role' => 'system', 'content' => trim($systemPrompt)]);

        // Route to appropriate AI provider
        $provider = $this->router->forUser($user, $data['provider'] ?? null);

        if ($data['stream'] ?? $settings?->stream_responses ?? true) {
            return $this->streamResponse($conversation, $user, $provider, $history, $data['content']);
        }

        $start    = now();
        $response = $provider->chat($history);

        $aiMessage = $conversation->messages()->create([
            'user_id'       => $user->id,
            'role'          => 'assistant',
            'content'       => $response['content'],
            'provider'      => $response['provider'],
            'model'         => $response['model'],
            'input_tokens'  => $response['input_tokens'],
            'output_tokens' => $response['output_tokens'],
            'latency_ms'    => $response['latency_ms'],
        ]);

        broadcast(new MessageCreated($aiMessage))->toOthers();
        $this->push->notifyMessage($user, $response['content'], $conversation->id, $response['provider']);

        // Update conversation metadata
        $conversation->update([
            'provider'    => $response['provider'],
            'model'       => $response['model'],
            'token_count' => $conversation->token_count + $response['input_tokens'] + $response['output_tokens'],
        ]);

        // Auto-generate title on first exchange
        if ($settings?->auto_title && !$conversation->title && $conversation->messages()->count() === 2) {
            $conversation->update(['title' => $this->generateTitle($data['content'])]);
        }

        if ($settings?->memory_enabled) {
            $this->memory->extractAndStore($user, $data['content']);
        }

        return response()->json($aiMessage);
    }

    private function streamResponse(Conversation $conversation, $user, $provider, array $history, string $userContent): StreamedResponse
    {
        return response()->stream(function () use ($conversation, $user, $provider, $history, $userContent) {
            $fullContent = '';
            $start = microtime(true);

            foreach ($provider->stream($history) as $chunk) {
                $fullContent .= $chunk;
                echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                ob_flush();
                flush();
            }

            $aiMessage = $conversation->messages()->create([
                'user_id'    => $user->id,
                'role'       => 'assistant',
                'content'    => $fullContent,
                'provider'   => $provider->getName(),
                'model'      => $provider->getModel(),
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            broadcast(new MessageCreated($aiMessage))->toOthers();
            $this->push->notifyMessage($user, $fullContent, $conversation->id, $provider->getName());

            echo "data: [DONE]\n\n";
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function generateTitle(string $content): string
    {
        return mb_substr($content, 0, 50) . (mb_strlen($content) > 50 ? '...' : '');
    }
}
