<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseSetting extends Model
{
    protected $fillable = [
        'licenses_required',
        'whatsapp_number',
        'price_monthly_cop',
        'price_yearly_cop',
        'license_features',
    ];

    protected $casts = [
        'licenses_required' => 'boolean',
        'license_features'  => 'array',
        'price_monthly_cop' => 'integer',
        'price_yearly_cop'  => 'integer',
    ];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1], [
            'licenses_required' => false,
            'whatsapp_number'   => '',
            'price_monthly_cop' => 50000,
            'price_yearly_cop'  => 480000,
            'license_features'  => [
                'Acceso completo a todos los modelos de IA',
                'Memoria semántica ilimitada',
                'App móvil Android e iOS',
                'Integración con Telegram',
                'Briefing diario personalizado',
                'Actualizaciones incluidas',
                'Soporte prioritario',
            ],
        ]);
    }
}
