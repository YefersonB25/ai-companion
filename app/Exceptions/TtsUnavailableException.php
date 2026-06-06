<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando ningún proveedor de TTS está configurado o todos fallan
 * (Fase 5: voz neural). El controlador la traduce a un 503 con mensaje claro.
 */
class TtsUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'No hay ningún proveedor de voz disponible en este momento.')
    {
        parent::__construct($message);
    }
}
