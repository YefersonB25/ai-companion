<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseRequest extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'company',
        'city',
        'plan_type',
        'status',
        'admin_notes',
        'catalog_sent_at',
        'whatsapp_clicked_at', // Fix #15
    ];

    protected $casts = [
        'catalog_sent_at'     => 'datetime',
        'whatsapp_clicked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
