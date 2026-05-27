<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProvider extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'model', 'api_key',
        'base_url', 'is_active', 'is_default', 'priority', 'config',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
        'config'     => 'array',
    ];

    protected $hidden = ['api_key'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDecryptedApiKey(): string
    {
        return decrypt($this->api_key);
    }
}
