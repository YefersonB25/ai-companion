<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemoryNode extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'type', 'label', 'content', 'attributes',
        'qdrant_id', 'importance', 'last_accessed_at',
        'access_count', 'parent_id',
    ];

    protected $casts = [
        'attributes'       => 'array',
        'importance'       => 'float',
        'last_accessed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MemoryNode::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MemoryNode::class, 'parent_id');
    }

    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }
}
