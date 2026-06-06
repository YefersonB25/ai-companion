<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProactiveLog extends Model
{
    public const UPDATED_AT = null; // solo created_at

    public const TYPE_PROACTIVE = 'proactive';
    public const TYPE_BRIEFING  = 'briefing';
    public const TYPE_CALENDAR  = 'calendar_trigger';

    protected $fillable = [
        'user_id', 'type', 'message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Devuelve los últimos mensajes enviados de un tipo para anti-repetición.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function recentMessagesFor(User $user, string $type, int $limit = 5, int $days = 7): \Illuminate\Support\Collection
    {
        return $user->proactiveLogs()
            ->where('type', $type)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('message');
    }
}
