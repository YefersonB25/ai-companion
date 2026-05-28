<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id', 'default_provider', 'default_model',
        'language', 'timezone', 'memory_enabled',
        'auto_title', 'stream_responses', 'routing_rules', 'persona',
        'briefing_enabled', 'briefing_time', 'briefing_city',
    ];

    protected $casts = [
        'memory_enabled'   => 'boolean',
        'auto_title'       => 'boolean',
        'stream_responses' => 'boolean',
        'briefing_enabled' => 'boolean',
        'routing_rules'    => 'array',
        'persona'          => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
