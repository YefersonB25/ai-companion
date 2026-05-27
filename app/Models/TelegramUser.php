<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramUser extends Model
{
    protected $fillable = [
        'user_id', 'telegram_id', 'telegram_username',
        'first_name', 'last_name',
        'active_conversation_id', 'state', 'state_data',
    ];

    protected $casts = [
        'state_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activeConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'active_conversation_id');
    }

    public function displayName(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: $this->telegram_username ?: "Usuario {$this->telegram_id}";
    }

    public function isLinked(): bool
    {
        return $this->user_id !== null;
    }
}
