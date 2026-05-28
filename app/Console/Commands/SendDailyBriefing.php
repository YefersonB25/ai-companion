<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BriefingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDailyBriefing extends Command
{
    protected $signature   = 'briefing:send {--user= : ID de usuario específico}';
    protected $description = 'Envía el briefing diario a los usuarios que lo tienen activado';

    public function handle(BriefingService $briefing): int
    {
        if ($userId = $this->option('user')) {
            $user = User::with('setting', 'deviceTokens', 'memoryNodes', 'aiProviders')->find($userId);
            if (! $user) {
                $this->error("Usuario {$userId} no encontrado.");
                return self::FAILURE;
            }

            $this->info("Enviando briefing a {$user->name}...");
            $briefing->sendForUser($user);
            $this->info('Listo.');
            return self::SUCCESS;
        }

        // Correr cada minuto y filtrar usuarios en su hora de briefing
        $nowUtc = Carbon::now('UTC');

        User::with('setting', 'deviceTokens', 'memoryNodes', 'aiProviders')
            ->whereHas('setting', fn($q) => $q->where('briefing_enabled', true))
            ->chunk(50, function ($users) use ($nowUtc, $briefing) {
                foreach ($users as $user) {
                    $settings = $user->setting;
                    $timezone = $settings->timezone ?? 'America/Bogota';
                    $userNow  = $nowUtc->copy()->setTimezone($timezone);

                    // Enviar si el minuto actual coincide con la hora configurada
                    [$hh, $mm] = explode(':', $settings->briefing_time ?? '08:00');
                    if ($userNow->hour === (int) $hh && $userNow->minute === (int) $mm) {
                        $this->info("  → Enviando a {$user->name} ({$timezone})");
                        $briefing->sendForUser($user);
                    }
                }
            });

        return self::SUCCESS;
    }
}
