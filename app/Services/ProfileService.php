<?php

namespace App\Services;

use App\Models\User;

class ProfileService
{
    /**
     * Convierte el perfil del usuario en un bloque de contexto para el sistema de IA.
     * Se incluye siempre (no semántico), antes de los recuerdos.
     */
    public function buildContextBlock(User $user): string
    {
        $profile = $user->profile;

        if (! $profile) {
            return '';
        }

        $lines = ["=== PERFIL PERSONAL DE {$user->name} ==="];

        // Personal
        if ($p = $profile->personal) {
            $personal = [];
            if (! empty($p['city']))           $personal[] = "vive en {$p['city']}" . (! empty($p['country']) ? ", {$p['country']}" : '');
            if (! empty($p['birthdate']))       $personal[] = "nacido el {$p['birthdate']}";
            if (! empty($p['occupation']))      $personal[] = "trabaja como {$p['occupation']}";
            if (! empty($p['marital_status']))  $personal[] = "{$p['marital_status']}";
            if (isset($p['children']) && $p['children'] > 0) $personal[] = "{$p['children']} hijo(s)";
            if ($personal) $lines[] = "Datos personales: " . implode(', ', $personal) . '.';
        }

        // Salud
        if ($h = $profile->health) {
            if (! empty($h['allergies']))       $lines[] = "Alergias: " . implode(', ', (array) $h['allergies']) . '.';
            if (! empty($h['conditions']))      $lines[] = "Condiciones de salud: " . implode(', ', (array) $h['conditions']) . '.';
            if (! empty($h['medications']))     $lines[] = "Medicamentos: " . implode(', ', (array) $h['medications']) . '.';
            if (! empty($h['blood_type']))      $lines[] = "Tipo de sangre: {$h['blood_type']}.";
            if (! empty($h['fitness_goals']))   $lines[] = "Metas de salud: " . implode(', ', (array) $h['fitness_goals']) . '.';
        }

        // Preferencias
        if ($pref = $profile->preferences) {
            if (! empty($pref['diet']))          $lines[] = "Dieta: {$pref['diet']}.";
            if (! empty($pref['favorite_foods'])) $lines[] = "Comidas favoritas: " . implode(', ', (array) $pref['favorite_foods']) . '.';
            if (! empty($pref['disliked_foods'])) $lines[] = "No le gusta: " . implode(', ', (array) $pref['disliked_foods']) . '.';
            if (! empty($pref['hobbies']))       $lines[] = "Hobbies: " . implode(', ', (array) $pref['hobbies']) . '.';
            if (! empty($pref['music']))         $lines[] = "Música favorita: " . implode(', ', (array) $pref['music']) . '.';
            if (! empty($pref['sports']))        $lines[] = "Deportes: " . implode(', ', (array) $pref['sports']) . '.';
        }

        // Rutinas
        if ($r = $profile->routines) {
            $routineLines = [];
            if (! empty($r['wake_time']))          $routineLines[] = "se despierta a las {$r['wake_time']}";
            if (! empty($r['sleep_time']))         $routineLines[] = "duerme a las {$r['sleep_time']}";
            if (! empty($r['work_schedule']))      $routineLines[] = "trabaja de {$r['work_schedule']}";
            if (! empty($r['exercise_frequency'])) $routineLines[] = "ejercicio {$r['exercise_frequency']}";
            if (! empty($r['exercise_type']))      $routineLines[] = "hace {$r['exercise_type']}";
            if ($routineLines) $lines[] = "Rutinas: " . implode(', ', $routineLines) . '.';
        }

        // Relaciones
        if ($rels = $profile->relationships) {
            $relLines = collect($rels)
                ->filter(fn($rel) => ! empty($rel['name']))
                ->map(function ($rel) {
                    $str = "{$rel['name']} ({$rel['relation']})";
                    if (! empty($rel['notes'])) $str .= " — {$rel['notes']}";
                    return $str;
                })->join(', ');
            if ($relLines) $lines[] = "Personas importantes: {$relLines}.";
        }

        // Metas
        if ($g = $profile->goals) {
            if (! empty($g['short_term']))  $lines[] = "Metas a corto plazo: " . implode(', ', (array) $g['short_term']) . '.';
            if (! empty($g['long_term']))   $lines[] = "Metas a largo plazo: " . implode(', ', (array) $g['long_term']) . '.';
            if (! empty($g['savings_goal'])) $lines[] = "Meta de ahorro: {$g['savings_goal']}.";
        }

        if (count($lines) === 1) {
            return ''; // solo el encabezado, sin datos
        }

        $lines[] = "=== FIN DEL PERFIL ===";

        return implode("\n", $lines);
    }
}
