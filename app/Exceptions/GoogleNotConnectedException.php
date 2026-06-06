<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando se intenta usar una API de Google (p.ej. Calendar) para un
 * usuario que no tiene una integración 'google' conectada.
 */
class GoogleNotConnectedException extends RuntimeException
{
    public function __construct(string $message = 'El usuario no ha conectado su cuenta de Google.')
    {
        parent::__construct($message);
    }
}
