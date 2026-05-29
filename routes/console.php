<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ejecutar cada minuto para verificar qué usuarios tienen su hora de briefing
Schedule::command('briefing:send')->everyMinute()->withoutOverlapping();

// Enviar notificaciones proactivas de IA a las 9:00 AM diariamente
Schedule::command('aria:proactive')->dailyAt('09:00');
