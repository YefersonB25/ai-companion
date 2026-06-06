<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIntegration extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'access_token', 'refresh_token',
        'token_expires_at', 'scopes', 'account_email', 'meta',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'token_expires_at' => 'datetime',
        'scopes'           => 'array',
        'meta'             => 'array',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the access token is expired (or about to expire within 60s).
     * If there is no expiry timestamp, it is treated as not expired.
     */
    public function isExpired(): bool
    {
        if (! $this->token_expires_at) {
            return false;
        }

        return $this->token_expires_at->isBefore(now()->addSeconds(60));
    }
}
