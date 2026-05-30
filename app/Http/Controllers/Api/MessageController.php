<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageCreated;
use App\Http\Controllers\Controller;
use App\Jobs\ExtractMemoryJob;
use App\Jobs\GenerateConversationTitle;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AI\AIRouter;
use App\Services\Memory\MemoryService;
use App\Services\ProfileService;
use App\Services\PushNotificationService;
use App\Services\Tools\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    private const DEFAULT_SYSTEM_PROMPT = <<<'PROMPT'
Eres **Aria**, asistente personal de IA creada por AI Companion. Eres inteligente, proactiva, cálida y capaz — como un amigo muy competente que siempre está disponible.

IDENTIDAD:
- Tu nombre es Aria. Si te preguntan quién eres, di que eres Aria, asistente personal de AI Companion.
- Fuiste diseñada para ser un asistente tipo Jarvis: manos libres, conversacional, integrado con el teléfono.
- Puedes ser activada por voz diciendo "Hey Aria", "Oye Aria" o "Hola Aria".
- Cuando el usuario pregunta qué puedes hacer, describe tus capacidades reales de forma conversacional.

TUS CAPACIDADES (responde con esto cuando te pregunten):
- 💬 Conversación: respondo preguntas, ayudo a decidir, redacto textos, investigo con búsqueda web en tiempo real
- 🧠 Memoria: recuerdo tus preferencias, eventos, personas importantes y los uso en futuras conversaciones
- 📞 Llamadas: puedo llamar a tus contactos con "llama a [nombre]"
- 💬 Mensajes: envío SMS y mensajes de WhatsApp con "manda un WhatsApp a [nombre] diciendo [mensaje]"
- 🎵 Música: reproduzco y controlo música en Spotify, YouTube Music o YouTube
- 📱 Apps: abro cualquier app instalada con "abre [nombre de la app]"
- 🔔 Notificaciones: leo y resumo tus notificaciones pendientes con "lee mis notificaciones"
- 🔒 Pantalla: bloqueo y enciendo la pantalla con comandos de voz
- 💡 Linterna: enciendo y apago la linterna del teléfono
- 🔊 Volumen y brillo: ajusto el volumen del media y el brillo de pantalla
- 📅 Recordatorios: creo recordatorios con fecha y hora específica
- 🌤 Clima: doy el clima actual de cualquier ciudad
- 🔍 Búsqueda: busco en internet información actualizada sobre cualquier tema
- 🚗 Modo conducción: respuestas ultra-cortas para cuando estás manejando

PRINCIPIOS:
- Sé proactivo: anticipa necesidades, sugiere mejoras, ofrece la siguiente acción útil
- Sé honesto: si no sabes algo, dilo; si una idea tiene riesgos, advierte antes de ejecutarla
- Sé conciso por defecto, detallado cuando el tema lo amerite
- Habla en el idioma del usuario (default español), tono natural y cálido como un amigo competente
- Recuerda y usa el contexto: nombres, fechas, preferencias, conversaciones previas

CAPACIDADES:
- Planifica: viajes (vuelos, hoteles, itinerarios), eventos, presupuestos, compras, agenda
- Redacta: emails, mensajes, propuestas, ideas, resúmenes
- Decide: analiza pros/contras, costos, riesgos, alternativas
- Investiga: con `web_search` para info actualizada (noticias, precios, productos, eventos)
- Adapta recomendaciones al perfil del usuario (alergias, gustos, presupuesto, restricciones)

HERRAMIENTAS DE INFORMACIÓN:
- `web_search` para cualquier dato que pueda haber cambiado (precios, noticias, lanzamientos)
- `get_weather` para clima de cualquier ciudad
- `get_datetime` para fecha/hora actual antes de planificar algo con tiempo

ACCIONES DEL TELÉFONO (solo si el usuario está en el cliente móvil):
Si el usuario pide explícitamente enviar un mensaje, llamar a alguien, reproducir música o abrir una app, incluye un bloque [ACTION]...[/ACTION] con JSON al FINAL de tu respuesta. El cliente móvil lo ejecuta automáticamente.

Formato JSON soportado:
- SMS (operadora): `{"type":"send_sms","contact":"<nombre o número>","message":"<texto>"}`
- WhatsApp: `{"type":"send_whatsapp","contact":"<nombre o número>","message":"<texto>"}` — usa esto cuando el usuario diga "WhatsApp", "whats", "mensaje de WhatsApp"
- Llamar: `{"type":"make_call","contact":"<nombre o número>"}`
- Reanudar música actual: `{"type":"play_music","resume":true}` — usar cuando digan "reanuda", "pon la música", "continúa" sin especificar canción
- Reproducir música específica: `{"type":"play_music","query":"<artista o canción>","app":"spotify"|"youtubemusic"|"youtube"}` — `app` solo si el usuario lo especifica
- Abrir app: `{"type":"open_app","name":"whatsapp"|"telegram"|"spotify"|"youtubemusic"|"youtube"|"gmail"|"maps"|"chrome"|"instagram"|"facebook"|"twitter"}`
- Recordatorio: `{"type":"set_reminder","when":"<ISO 8601 con timezone del usuario>","message":"<texto del recordatorio>"}`. Convierte expresiones naturales como "mañana a las 3pm", "en una hora", "el viernes" a una fecha ISO absoluta (usa `get_datetime` primero si necesitas la fecha actual).
- Apagar pantalla/bloquear: `{"type":"screen_off"}`
- Encender pantalla: `{"type":"screen_on"}`
- Linterna encender: `{"type":"flashlight","on":true}`
- Linterna apagar: `{"type":"flashlight","on":false}`
- Subir volumen: `{"type":"set_volume","level":8}` (0-15)
- Bajar volumen: `{"type":"set_volume","level":3}`
- Brillo pantalla: `{"type":"set_brightness","level":128}` (0-255)
- Leer notificaciones: `{"type":"read_notifications"}`

Reglas para acciones:
- Antes del bloque, escribe una confirmación natural en español (ej. "Listo, llamando a María." o "Te abro Spotify con Bad Bunny.")
- NO uses bloques [ACTION] si el usuario solo está conversando — solo cuando pide hacer algo concreto
- Si el usuario no especifica reproductor de música, omite el campo `app` y deja que el cliente le pregunte
- Si la petición es ambigua ("escríbele algo"), pregunta primero qué decir antes de emitir la acción

Ejemplo:
Usuario: "envíale un mensaje a María diciéndole que voy en camino"
Respuesta: "Listo, le envío el mensaje a María.
[ACTION]{"type":"send_sms","contact":"María","message":"Voy en camino"}[/ACTION]"

MODO VOZ (cuando voice=true):
- Responde en máximo 2-3 oraciones cortas y naturales
- Sin markdown, sin listas, sin títulos — solo texto conversacional
- Si la respuesta requiere más detalle, da un resumen y ofrece explicar más
- Habla como si fuera una conversación, no un documento

MODO CONDUCCIÓN (cuando driving_mode=true):
- Respuestas de máximo 1-2 oraciones MUY cortas
- Solo responde lo esencial, sin preguntas de vuelta
- Prioriza la seguridad: si la pregunta requiere concentración, di "Te respondo cuando pares"

REGLAS:
- Si el usuario menciona algo personal nuevo (alergia, preferencia, evento), guárdalo mentalmente para futuras conversaciones
- No inventes precios, fechas o disponibilidad — siempre verifica con `web_search`
- No des consejos médicos, legales o financieros vinculantes; recomienda profesional cuando aplique
- Si pides datos que aún no tienes (presupuesto, fecha, destino), pregunta de forma natural en vez de asumir
PROMPT;

    public function __construct(
        private AIRouter $router,
        private MemoryService $memory,
        private ProfileService $profile,
        private PushNotificationService $push,
        private ToolRegistry $tools,
    ) {}

    public function send(Request $request, Conversation $conversation): JsonResponse|StreamedResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'content'      => 'required|string',
            'provider'     => 'nullable|string',
            'stream'       => 'nullable|boolean',
            'image'        => 'nullable|image|max:5120',
            'voice'        => 'nullable|boolean',
            'driving_mode' => 'nullable|boolean',
            'location'     => 'nullable|array',
        ]);

        $user = $request->user();

        // Handle optional image upload
        $imageUrl = null;
        if ($request->hasFile('image')) {
            $path     = $request->file('image')->store('messages', 'public');
            $imageUrl = asset('storage/' . $path);
        }

        // Save user message
        $userMessage = $conversation->messages()->create([
            'user_id'   => $user->id,
            'role'      => 'user',
            'content'   => $data['content'],
            'image_url' => $imageUrl,
        ]);

        // Asynchronously extract memories from the user's message
        $settings = $user->setting;
        if ($settings?->memory_enabled) {
            ExtractMemoryJob::dispatch($user->id, $data['content'], $conversation->id);
        }

        // Build message history for the AI (with vision support for messages with images)
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(function ($m) {
                if ($m->image_url) {
                    return [
                        'role'    => $m->role,
                        'content' => [
                            ['type' => 'image', 'source' => ['type' => 'url', 'url' => $m->image_url]],
                            ['type' => 'text',  'text'   => $m->content],
                        ],
                    ];
                }
                return ['role' => $m->role, 'content' => $m->content];
            })
            ->toArray();

        // Build dynamic context parts from voice/driving/location fields
        $contextParts = [];

        if ($data['voice'] ?? false) {
            $contextParts[] = "[MODO VOZ ACTIVO: responde en máximo 2-3 oraciones cortas, sin markdown]";
        }

        if ($data['driving_mode'] ?? false) {
            $contextParts[] = "[MODO CONDUCCIÓN: respuesta de máximo 1 oración, solo lo esencial]";
        }

        if (!empty($data['location'])) {
            $loc  = $data['location'];
            $city = $loc['city'] ?? '';
            $lat  = $loc['lat'] ?? '';
            $lng  = $loc['lng'] ?? '';
            if ($city) {
                $contextParts[] = "[UBICACIÓN ACTUAL DEL USUARIO: $city (lat: $lat, lng: $lng)]";
            } elseif ($lat && $lng) {
                $contextParts[] = "[UBICACIÓN ACTUAL DEL USUARIO: lat=$lat, lng=$lng]";
            }
        }

        // Build system prompt with profile + persona + memory
        $systemPrompt = self::DEFAULT_SYSTEM_PROMPT;

        // Perfil estructurado del usuario (siempre presente)
        $profileContext = $this->profile->buildContextBlock($user);
        if ($profileContext) {
            $systemPrompt .= "\n\n" . $profileContext;
        }

        if ($settings?->persona) {
            $personaName = $settings->persona['name'] ?? null;
            if ($personaName) {
                $systemPrompt .= "\n\nTu nombre es {$personaName}. Si el usuario te pregunta cómo te llamas o se refiere a ti, identifícate como {$personaName}.";
            }
            $personaPrompt = $settings->persona['prompt'] ?? '';
            if ($personaPrompt) {
                $systemPrompt .= "\n\n" . $personaPrompt;
            }
        }

        if ($settings?->memory_enabled) {
            $memoryContext = $this->memory->buildContextPrompt($user, $data['content']);
            if ($memoryContext) {
                $systemPrompt .= "\n\n" . $memoryContext;
            }
        }

        if (!empty($contextParts)) {
            $systemPrompt .= "\n\nCONTEXTO ACTUAL:\n" . implode("\n", $contextParts);
        }

        array_unshift($history, ['role' => 'system', 'content' => trim($systemPrompt)]);

        // Route to appropriate AI provider - return friendly errors for setup issues
        try {
            $provider = $this->router->forUser($user, $data['provider'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error'  => 'no_provider',
                'message' => 'No tienes ningún proveedor de IA configurado. Ve a "Proveedores IA" para agregar uno (Gemini es gratis).',
                'setup_url' => '/providers',
            ], 400);
        }

        $useTools  = $provider->supportsTools();

        // Streaming is disabled when tools are active (agent loop runs synchronously)
        if (!$useTools && ($data['stream'] ?? $settings?->stream_responses ?? true)) {
            return $this->streamResponse($conversation, $user, $provider, $history, $data['content'], $settings);
        }

        try {
            $start    = now();
            $response = $useTools
                ? $this->resolveTools($provider, $history, $user)
                : $provider->chat($history);
        } catch (\Throwable $e) {
            $msg = $this->humanizeProviderError($e->getMessage());
            return response()->json([
                'error'   => 'provider_error',
                'message' => $msg,
                'detail'  => config('app.debug') ? $e->getMessage() : null,
            ], 502);
        }

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

        // Auto-generate title after first AI response (dispatched to queue)
        if (!$conversation->title && $conversation->messages()->count() <= 4) {
            GenerateConversationTitle::dispatch($conversation->id, $user->id, $data['content']);
        }

        if ($settings?->memory_enabled) {
            $this->memory->extractAndStore($user, $data['content']);
        }

        return response()->json($aiMessage);
    }

    /**
     * Translate cryptic provider API errors into actionable Spanish messages.
     */
    private function humanizeProviderError(string $error): string
    {
        $lower = strtolower($error);

        if (str_contains($lower, 'api key') && (str_contains($lower, 'leaked') || str_contains($lower, 'reported'))) {
            return 'Tu API key fue reportada como filtrada y revocada por el proveedor. Genera una nueva en su portal y actualízala en "Proveedores IA".';
        }
        if (str_contains($lower, 'invalid api key') || str_contains($lower, 'api_key') || str_contains($lower, 'permission_denied') || str_contains($lower, 'unauthorized') || str_contains($lower, '401') || str_contains($lower, '403')) {
            return 'Tu API key no es válida o no tiene permisos. Revisa que la pegaste correctamente y que el proyecto en el portal del proveedor esté activo.';
        }
        if (str_contains($lower, 'quota') || str_contains($lower, 'rate limit') || str_contains($lower, '429')) {
            return 'Excediste el límite gratuito o de tasa de tu proveedor. Espera unos minutos o sube de plan.';
        }
        if (str_contains($lower, 'model') && str_contains($lower, 'not found')) {
            return 'El modelo configurado ya no existe o no está disponible para tu cuenta. Cambia el modelo en "Proveedores IA".';
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
            return 'El proveedor tardó demasiado en responder. Inténtalo de nuevo.';
        }
        if (str_contains($lower, 'safety') || str_contains($lower, 'blocked')) {
            return 'El proveedor bloqueó la respuesta por filtros de contenido. Reformula la pregunta.';
        }

        return 'El proveedor de IA devolvió un error inesperado. Verifica tu API key y modelo en "Proveedores IA", o cambia de proveedor.';
    }

    private function resolveTools($provider, array $messages, ?User $user): array
    {
        $tools   = $provider->getName() === 'claude'
            ? $this->tools->forClaude()
            : $this->tools->forOpenAI();

        $history = $messages;
        $maxIter = 5;

        for ($i = 0; $i < $maxIter; $i++) {
            $response = $provider->chatWithTools($history, $tools);

            if ($response['type'] === 'text') {
                return $response;
            }

            // Append assistant message(s) containing the tool calls
            foreach ($response['messages_to_append'] as $msg) {
                $history[] = $msg;
            }

            // Execute tools and append results
            if ($provider->getName() === 'claude') {
                // Claude expects all tool results batched in one user message
                $resultBlocks = [];
                foreach ($response['tool_calls'] as $call) {
                    $result = $this->tools->execute($call['name'], $call['input'], $user);
                    $resultBlocks[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $result];
                }
                $history[] = ['role' => 'user', 'content' => $resultBlocks];
            } else {
                foreach ($response['tool_calls'] as $call) {
                    $result    = $this->tools->execute($call['name'], $call['input'], $user);
                    $history[] = $provider->buildToolResultMessage($call, $result);
                }
            }
        }

        // Max iterations reached — get final answer without tools
        return array_merge($provider->chat($history), ['type' => 'text']);
    }

    private function streamResponse(Conversation $conversation, $user, $provider, array $history, string $userContent, $settings = null): StreamedResponse
    {
        return response()->stream(function () use ($conversation, $user, $provider, $history, $userContent, $settings) {
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

            // Auto-generate title after first AI response (dispatched to queue)
            if (!$conversation->title && $conversation->messages()->count() <= 4) {
                GenerateConversationTitle::dispatch($conversation->id, $user->id, $userContent);
            }

            echo "data: [DONE]\n\n";
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

}
