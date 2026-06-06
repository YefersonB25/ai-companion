<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecretRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro de secretos (DOCUMENTACIÓN — sin valores).
 *
 * PRINCIPIO DE SEGURIDAD: este controlador NUNCA guarda ni devuelve el VALOR del
 * secreto. Solo gestiona metadatos. Los campos `configured` y `last4` se calculan
 * en tiempo de request leyendo `env($r->env_var)` y NO se persisten. Cualquier
 * `value` que llegue en el body se ignora (no existe tal columna).
 */
class SecretRegistryController extends Controller
{
    // Metadatos editables. `value` NO está aquí a propósito: nunca se persiste.
    private const METADATA_RULES = [
        'label'           => 'required|string|max:255',
        'app'             => 'required|in:backend,web,mobile,shared',
        'provider'        => 'nullable|string|max:255',
        'description'     => 'required|string',
        'used_in'         => 'nullable|string',
        'rotation_url'    => 'nullable|string|max:2048',
        'last_rotated_at' => 'nullable|date',
        'status'          => 'required|in:active,needs_rotation,deprecated',
        'notes'           => 'nullable|string',
        'sort_order'      => 'nullable|integer',
    ];

    // ─────────────────────────────────────────────
    // GET /api/admin/secrets
    // ─────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $secrets = SecretRegistry::orderBy('sort_order')
            ->orderBy('app')
            ->orderBy('label')
            ->get()
            ->map(fn (SecretRegistry $r) => $this->present($r));

        return response()->json($secrets);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/secrets
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'env_var' => 'required|string|max:255|unique:secret_registry,env_var',
            ...self::METADATA_RULES,
        ]);

        // Defensa explícita: jamás aceptamos el valor del secreto.
        $secret = SecretRegistry::create($this->onlyMetadata($data));

        return response()->json($this->present($secret), 201);
    }

    // ─────────────────────────────────────────────
    // PUT /api/admin/secrets/{secretRegistry}
    // ─────────────────────────────────────────────
    public function update(Request $request, SecretRegistry $secretRegistry): JsonResponse
    {
        $rules = [];
        foreach (self::METADATA_RULES as $field => $rule) {
            // En update todos los metadatos son opcionales (sometimes).
            $rules[$field] = 'sometimes|' . str_replace('required|', '', $rule);
        }

        $data = $request->validate($rules);

        // `value` (o cualquier otra cosa fuera de los metadatos) se ignora: no existe columna.
        $secretRegistry->update($this->onlyMetadata($data));

        return response()->json($this->present($secretRegistry->fresh()));
    }

    // ─────────────────────────────────────────────
    // DELETE /api/admin/secrets/{secretRegistry}
    // ─────────────────────────────────────────────
    public function destroy(SecretRegistry $secretRegistry): JsonResponse
    {
        // Solo borra el documento; no afecta el .env.
        $secretRegistry->delete();

        return response()->json(['deleted' => true]);
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /**
     * Serializa un registro con todos los metadatos + campos calculados al vuelo.
     * NUNCA incluye el valor del secreto: solo `configured` (bool) y `last4`.
     */
    private function present(SecretRegistry $r): array
    {
        return [
            'id'              => $r->id,
            'env_var'         => $r->env_var,
            'label'           => $r->label,
            'app'             => $r->app,
            'provider'        => $r->provider,
            'description'     => $r->description,
            'used_in'         => $r->used_in,
            'rotation_url'    => $r->rotation_url,
            'last_rotated_at' => optional($r->last_rotated_at)->toDateString(),
            'status'          => $r->status,
            'notes'           => $r->notes,
            'sort_order'      => $r->sort_order,
            'created_at'      => $r->created_at,
            'updated_at'      => $r->updated_at,
            // Calculados en tiempo de request, NO persistidos:
            'configured'      => $r->isConfigured(),
            'last4'           => $r->last4(),
        ];
    }

    /**
     * Filtra el payload a solo columnas de metadatos permitidas.
     * Garantiza que `value` y similares jamás lleguen a la BD.
     */
    private function onlyMetadata(array $data): array
    {
        return array_intersect_key($data, array_flip(array_keys(self::METADATA_RULES)) + ['env_var' => 0]);
    }
}
