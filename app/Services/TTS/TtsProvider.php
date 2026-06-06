<?php

namespace App\Services\TTS;

/**
 * Contrato común para los proveedores de voz neural (Fase 5).
 *
 * Cada adaptador habla con una API distinta (ElevenLabs, OpenAI, …) pero
 * todos exponen la misma operación: convertir texto a bytes de audio mp3.
 */
interface TtsProvider
{
    /**
     * Sintetiza el texto a audio mp3 y devuelve el cuerpo crudo (bytes).
     *
     * @param  array  $opts  Opciones por proveedor (p.ej. 'voice' para sobreescribir la voz).
     * @return string Bytes mp3 (audio/mpeg).
     *
     * @throws \RuntimeException si la API responde con un error no-2xx.
     */
    public function synthesize(string $text, array $opts = []): string;

    /** Nombre corto del proveedor (p.ej. 'elevenlabs', 'openai'). */
    public function getName(): string;

    /** True si el proveedor tiene la api_key necesaria para operar. */
    public function isConfigured(): bool;
}
