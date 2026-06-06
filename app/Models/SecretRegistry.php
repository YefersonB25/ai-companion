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

    /** Caché por-proceso del .env parseado. */
    protected static ?array $envFileCache = null;

    /**
     * ¿Está configurada esta env? Se calcula al vuelo; nunca se persiste.
     */
    public function isConfigured(): bool
    {
        return $this->resolveValue() !== null;
    }

    /**
     * Últimos 4 caracteres del valor (o null si está vacío).
     * NUNCA devuelve el valor completo. Se calcula al vuelo; nunca se persiste.
     */
    public function last4(): ?string
    {
        $value = $this->resolveValue();

        return $value === null ? null : substr($value, -4);
    }

    /**
     * Resuelve el valor de la env leyendo el archivo .env directamente (funciona
     * aunque config esté cacheada en prod, donde env() devuelve null), con
     * fallback a env() para entornos sin .env en disco (p.ej. tests con putenv).
     * Devuelve null si está ausente o vacía. NUNCA se persiste ni se expone entero.
     */
    protected function resolveValue(): ?string
    {
        $fromFile = static::envFileValues()[$this->env_var] ?? null;
        if ($fromFile !== null && $fromFile !== '') {
            return $fromFile;
        }

        $fromEnv = env($this->env_var);

        return ($fromEnv === null || $fromEnv === '') ? null : (string) $fromEnv;
    }

    /** Parsea el archivo .env del proyecto a un mapa clave=>valor (cacheado). */
    protected static function envFileValues(): array
    {
        if (static::$envFileCache !== null) {
            return static::$envFileCache;
        }

        static::$envFileCache = [];
        $path = base_path('.env');

        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                    continue;
                }
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                // Quita comillas envolventes si las hay.
                if (strlen($val) >= 2
                    && ($val[0] === '"' || $val[0] === "'")
                    && substr($val, -1) === $val[0]) {
                    $val = substr($val, 1, -1);
                }
                static::$envFileCache[$key] = $val;
            }
        }

        return static::$envFileCache;
    }
}
