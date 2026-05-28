<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id', 'personal', 'health', 'preferences', 'routines', 'relationships', 'goals',
    ];

    protected $casts = [
        'personal'      => 'array',
        'health'        => 'array',
        'preferences'   => 'array',
        'routines'      => 'array',
        'relationships' => 'array',
        'goals'         => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
