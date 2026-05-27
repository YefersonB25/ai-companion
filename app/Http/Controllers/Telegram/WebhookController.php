<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBotService;
use App\Telegram\Commands\HelpCommand;
use App\Telegram\Commands\LoginCommand;
use App\Telegram\Commands\MemoryCommand;
use App\Telegram\Commands\NewCommand;
use App\Telegram\Commands\RegisterCommand;
use App\Telegram\Commands\StartCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class WebhookController extends Controller
{
    public function __construct(private TelegramBotService $bot) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            // Register commands before processing
            Telegram::addCommands([
                StartCommand::class,
                LoginCommand::class,
                RegisterCommand::class,
                NewCommand::class,
                MemoryCommand::class,
                HelpCommand::class,
            ]);

            $update = Telegram::commandsHandler(true);

            // If the update has a text message and wasn't consumed by a command, handle as chat
            $this->bot->handleUpdate($request->all());
        } catch (\Throwable $e) {
            Log::error('Telegram webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'body'  => $request->all(),
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
