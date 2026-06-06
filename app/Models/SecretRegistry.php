<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de secretos (DOCUMENTACIÓN — sin valores).
 *
 * PRINCIPIO DE SEGURIDAD: este modelo NUNCA almacena el VALOR del secreto.
 * Solo guarda metadatos (qué env_var es, de qué app, dónde se usa, dónde rotarla,
 * estado, etc.). El valor real vive SOLO en `.env`. El "estado de configuración"
 * (`configured`) y los "últimos 4 dígitos" (`last4`) se calculan en tiempo de
 * request leyendo `env($this->env_var)` y NO se persisten. No hay columna de valor.
 */
class SecretRegistry extends Model
{
    protected $table = 'secret_registry';

    protected $fillable = [
        'env_var',
        'label',
        'app',
        'provider',
        'description',
        'used_in',
        'rotation_url',
        'last_rotated_at',
        'status',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'last_rotated_at' => 'date',
        'sort_order'      => 'integer',
    ];

    /**
     * ¿Está configurada esta env en el entorno actual?
     * Se calcula al vuelo; nunca se persiste.
     */
    public function isConfigured(): bool
    {
        return ! empty(env($this->env_var));
    }

    /**
     * Últimos 4 caracteres del valor en env (o null si está vacío).
     * NUNCA devuelve el valor completo. Se calcula al vuelo; nunca se persiste.
     */
    public function last4(): ?string
    {
        $value = env($this->env_var);

        if (empty($value)) {
            return null;
        }

        return substr((string) $value, -4);
    }
}
