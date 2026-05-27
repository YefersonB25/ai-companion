<?php

namespace App\Telegram\Commands;

use Telegram\Bot\Commands\Command;

class StartCommand extends Command
{
    protected string $name        = 'start';
    protected string $description = 'Iniciar o vincular tu cuenta de AI Companion';

    public function handle(): void
    {
        $this->replyWithMessage([
            'text'       => $this->buildWelcome(),
            'parse_mode' => 'Markdown',
        ]);
    }

    private function buildWelcome(): string
    {
        return <<<MD
        🧠 *Bienvenido a AI Companion*

        Tu asistente personal de IA con memoria.

        Para empezar, vincula tu cuenta:
        👉 Usa /login para autenticarte

        *Comandos disponibles:*
        /login — Vincular cuenta existente
        /register — Crear cuenta nueva
        /new — Nueva conversación
        /memory — Ver tu memoria
        /help — Ver todos los comandos
        MD;
    }
}
