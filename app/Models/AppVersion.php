<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppVersion extends Model
{
    protected $fillable = ['platform', 'version', 'version_code', 'changelog', 'download_url', 'is_required'];

    protected $casts = [
        'changelog'   => 'array',
        'is_required' => 'boolean',
    ];
}
