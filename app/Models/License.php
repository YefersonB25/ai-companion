<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class License extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'type',
        'status',
        'starts_at',
        'expires_at',
        'granted_by',
        'price_paid',
        'notes',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'expires_at' => 'datetime',
        'price_paid' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (License $license) {
            if (empty($license->key)) {
                $license->key = strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    // ─── Scopes ───────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'active')->where('expires_at', '<=', now());
        })->orWhere('status', 'expired');
    }

    // ─── Helpers ──────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at->isFuture();
    }

    public function daysRemaining(): int
    {
        if (! $this->isActive()) {
            return 0;
        }
        return (int) now()->diffInDays($this->expires_at);
    }
}
