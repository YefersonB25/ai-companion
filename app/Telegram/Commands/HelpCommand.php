<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;

class HelpCommand extends Command
{
    protected string $name        = 'help';
    protected string $description = 'Mostrar todos los comandos disponibles';

    public function handle(): void
    {
        $this->replyWithMessage([
            'text' => <<<MD
            🧠 *AI Companion — Comandos*

            /start — Bienvenida e instrucciones
            /login — Vincular cuenta existente
            /register — Crear cuenta nueva
            /new — Nueva conversación
            /memory — Ver tu memoria personal
            /help — Este mensaje

            💬 *Cómo chatear:*
            Simplemente escribe cualquier mensaje y tu asistente responderá usando la IA configurada en tu cuenta.

            🔗 *Gestiona tu cuenta:*
            Accede al panel web o la app mobile para configurar proveedores de IA, ver el mapa mental y más.
            MD,
            'parse_mode' => 'Markdown',
        ]);
    }
}
