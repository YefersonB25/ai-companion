<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AI\AIRouter;
use App\Services\PushNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendProactiveNotifications extends Command
{
    protected $signature   = 'aria:proactive';
    protected $description = 'Send proactive AI-generated reminders to users';

    public function handle(AIRouter $router, PushNotificationService $push): int
    {
        $usersProcessed      = 0;
        $notificationsSent   = 0;

        $today = Carbon::now()->translatedFormat('l, F j, Y');

        User::with(['memoryNodes', 'aiProviders', 'deviceTokens'])
            ->whereHas('deviceTokens', fn ($q) => $q->where('platform', 'expo'))
            ->chunk(50, function ($users) use (
                $router,
                $push,
                $today,
                &$usersProcessed,
                &$notificationsSent
            ) {
                foreach ($users as $user) {
                    $usersProcessed++;

                    try {
                        // Get top 20 memory nodes ordered by importance then recency
                        $nodes = $user->memoryNodes()
                            ->orderByDesc('importance')
                            ->orderByDesc('updated_at')
                            ->limit(20)
                            ->get();

                        if ($nodes->isEmpty()) {
                            $this->line("  → {$user->name}: no memory nodes, skipping.");
                            sleep(2);
                            continue;
                        }

                        // Build a human-readable summary of the memory nodes
                        $memorySummary = $nodes->map(function ($node) {
                            $label   = $node->label ?? $node->type;
                            $content = $node->content ?? '';
                            return "- [{$label}]: {$content}";
                        })->implode("\n");

                        $prompt = "Today is {$today}. Here are facts about the user:\n{$memorySummary}\n\n"
                            . "Based on this, generate ONE brief, helpful proactive message to send as a push notification. "
                            . "It should be relevant to today (check for upcoming events, habits, goals). "
                            . "If nothing relevant, return empty string. "
                            . "Return ONLY the message text in Spanish, max 80 characters, or empty string.";

                        $messages = [
                            ['role' => 'user', 'content' => $prompt],
                        ];

                        // Use user's default provider; prefer a fast model if available
                        $provider = $router->forUser($user);
                        $response = $provider->chat($messages, ['max_tokens' => 120]);

                        $message = trim($response['content'] ?? '');

                        if ($message === '') {
                            $this->line("  → {$user->name}: AI returned empty, no notification sent.");
                            sleep(2);
                            continue;
                        }

                        // Truncate to 80 chars just in case the model exceeded the limit
                        if (mb_strlen($message) > 80) {
                            $message = mb_substr($message, 0, 80);
                        }

                        $push->notifyUser($user, 'Aria 🤖', $message);
                        $notificationsSent++;

                        $this->info("  → {$user->name}: \"{$message}\"");
                    } catch (\Throwable $e) {
                        $this->warn("  ✗ {$user->name}: {$e->getMessage()}");
                        Log::warning('aria:proactive failed for user', [
                            'user_id' => $user->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }

                    sleep(2);
                }
            });

        $this->info("Done. Processed: {$usersProcessed} users, sent: {$notificationsSent} notifications.");

        Log::info('aria:proactive completed', [
            'users_processed'    => $usersProcessed,
            'notifications_sent' => $notificationsSent,
        ]);

        return self::SUCCESS;
    }
}
