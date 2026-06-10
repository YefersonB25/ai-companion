<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Construye el contexto rolling de una conversación.
 *
 * Incluye los últimos N mensajes para que Aria entienda el contexto
 * sin necesidad de que el usuario repita el tema.
 *
 * Mejora: Usuario puede decir "qué piensas?" en lugar de
 * "qué piensas sobre lo que dijimos hace poco?"
 */
class ConversationContextBuilder
{
    /**
     * Número de mensajes previos a incluir en el contexto.
     * 5 = últimos 5 turnos (2-3 intercambios usuario-asistente).
     */
    private const CONTEXT_WINDOW = 5;

    /**
     * Construye un bloque de contexto con los últimos mensajes de la conversación.
     * Incluye rol, contenido resumido y marca de tiempo si es relevante.
     *
     * @param  Conversation $conversation
     * @param  int|null     $excludeMessageId  ID del mensaje actual (no incluir en contexto)
     * @return string       Bloque de contexto formateado o string vacío si no hay historial
     */
    public function buildRecentContextBlock(
        Conversation $conversation,
        ?int $excludeMessageId = null
    ): string {
        $messages = $conversation
            ->messages()
            ->orderBy('created_at', 'desc')
            ->limit(self::CONTEXT_WINDOW + 1)
            ->get();

        // Filtrar el mensaje actual si se proporciona ID
        if ($excludeMessageId) {
            $messages = $messages->filter(fn ($m) => $m->id !== $excludeMessageId);
        }

        if ($messages->isEmpty()) {
            return '';
        }

        // Invertir orden para mostrar cronológicamente (antiguo → nuevo)
        $messages = $messages->reverse();

        $contextLines = ['CONTEXTO RECIENTE DE LA CONVERSACIÓN:'];

        foreach ($messages as $msg) {
            $role = $msg->role === 'user' ? 'Tú' : 'Aria';
            $content = $this->summarizeContent($msg->content);

            // Formatos de contexto según el rol
            if ($msg->role === 'user') {
                $contextLines[] = "• {$role}: \"{$content}\"";
            } else {
                $contextLines[] = "• {$role}: {$content}";
            }
        }

        // Agregar inferencia de tema actual (simple heurística)
        $inferredTopic = $this->inferCurrentTopic($messages);
        if ($inferredTopic) {
            $contextLines[] = "\nTema actual: {$inferredTopic}";
        }

        return implode("\n", $contextLines);
    }

    /**
     * Summariza el contenido del mensaje para mantener el contexto compacto.
     * Limita a 150 caracteres y añade "..." si se trunca.
     *
     * @param  string $content
     * @return string Contenido resumido
     */
    private function summarizeContent(string $content): string
    {
        $maxLength = 150;
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        return substr($content, 0, $maxLength) . '...';
    }

    /**
     * Intenta inferir el tema actual de la conversación.
     * Simple heurística: palabras clave frecuentes en los últimos mensajes.
     *
     * @param  \Illuminate\Support\Collection $messages
     * @return string|null Tema inferido o null
     */
    private function inferCurrentTopic($messages): ?string
    {
        if ($messages->isEmpty()) {
            return null;
        }

        // Palabras clave que indican tema
        $keywords = [
            'película' => 'recomendación de películas',
            'libro' => 'recomendación de libros',
            'viaje' => 'planificación de viaje',
            'código' => 'ayuda de programación',
            'receta' => 'recetas de cocina',
            'ejercicio' => 'fitness y ejercicio',
            'salud' => 'consejos de salud',
            'relación' => 'relaciones personales',
            'trabajo' => 'consejos laborales',
            'api' => 'integración de APIs',
            'database' => 'base de datos',
            'javascript' => 'desarrollo web',
            'python' => 'desarrollo en Python',
        ];

        // Concatenar últimos mensajes y buscar palabras clave
        $recentContent = $messages
            ->map(fn ($m) => strtolower($m->content))
            ->implode(' ');

        foreach ($keywords as $keyword => $topic) {
            if (strpos($recentContent, $keyword) !== false) {
                return $topic;
            }
        }

        // Si no hay palabra clave reconocida, retornar null
        return null;
    }

    /**
     * Construye un bloque de contexto que incluye:
     * - Últimos N mensajes
     * - Tema inferido
     * - Pronóstico de siguiente pregunta (simple heurística)
     *
     * Más detallado que buildRecentContextBlock.
     *
     * @param  Conversation $conversation
     * @return string
     */
    public function buildEnhancedContextBlock(Conversation $conversation): string
    {
        $contextBlock = $this->buildRecentContextBlock($conversation);

        if (empty($contextBlock)) {
            return '';
        }

        // Agregar predicción de siguiente pregunta
        $lastMessage = $conversation->messages()->latest()->first();
        if ($lastMessage?->role === 'assistant') {
            $prediction = $this->predictNextQuestion($lastMessage->content);
            if ($prediction) {
                $contextBlock .= "\n\nProxima pregunta probable: {$prediction}";
            }
        }

        return $contextBlock;
    }

    /**
     * Predice cuál podría ser la siguiente pregunta del usuario.
     * Simple heurística basada en el tipo de respuesta.
     *
     * @param  string $assistantMessage
     * @return string|null Predicción o null
     */
    private function predictNextQuestion(string $assistantMessage): ?string
    {
        $message = strtolower($assistantMessage);

        // Si es una recomendación, el siguiente paso es probablemente más detalles
        if (
            strpos($message, 'recomiendo') !== false ||
            strpos($message, 'sugiero') !== false
        ) {
            return 'Cuéntame más detalles sobre esa opción';
        }

        // Si es una pregunta clarificadora, el usuario probablemente responde
        if (strpos($message, '¿') !== false) {
            return 'Responder la pregunta clarificadora de Aria';
        }

        // Si es una lista, el usuario probablemente pide detalles de uno
        if (strpos($message, '1.') !== false || strpos($message, '-') !== false) {
            return 'Seleccionar una opción de la lista para más detalles';
        }

        return null;
    }
}
