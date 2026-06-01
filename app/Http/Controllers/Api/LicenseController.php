<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LicenseCatalogMail;
use App\Models\License;
use App\Models\LicenseRequest;
use App\Models\LicenseSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class LicenseController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/license/status
    // ─────────────────────────────────────────────
    public function status(Request $request): JsonResponse
    {
        $user     = $request->user();
        $settings = LicenseSetting::current();

        // Fix #8: exclude revoked licenses from the fallback
        /** @var License|null $license */
        $license = $user->licenses()->active()->latest('expires_at')->first()
            ?? $user->licenses()->whereIn('status', ['expired'])->latest()->first();

        $pendingRequest = $user->licenseRequests()
            ->where('status', 'pending')
            ->latest()
            ->first();

        return response()->json([
            'licenses_required'  => $settings->licenses_required,
            'has_active_license' => $license?->isActive() ?? false,
            'license'            => $license ? [
                'id'             => $license->id,
                'key'            => $license->key,
                'type'           => $license->type,
                'status'         => $license->status,
                'starts_at'      => $license->starts_at,
                'expires_at'     => $license->expires_at,
                'days_remaining' => $license->daysRemaining(),
                'is_active'      => $license->isActive(),
            ] : null,
            'pending_request' => $pendingRequest ? [
                'id'         => $pendingRequest->id,
                'plan_type'  => $pendingRequest->plan_type,
                'status'     => $pendingRequest->status,
                'created_at' => $pendingRequest->created_at,
            ] : null,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/license/whatsapp/{licenseRequest}/{plan}  (público)
    // Registra el clic y redirige a WhatsApp
    // ─────────────────────────────────────────────
    public function whatsappRedirect(LicenseRequest $licenseRequest, string $plan): RedirectResponse
    {
        abort_unless(in_array($plan, ['monthly', 'yearly']), 404);

        // Fix #12: abort if WhatsApp not configured
        $settings = LicenseSetting::current();
        $number   = preg_replace('/[^0-9]/', '', $settings->whatsapp_number ?? '');
        abort_if(empty($number), 400, 'WhatsApp no configurado. Contacta al administrador.');

        // Fix #15: whatsapp_clicked_at now in $fillable — this update works
        $licenseRequest->update([
            'plan_type'           => $plan,
            'whatsapp_clicked_at' => $licenseRequest->whatsapp_clicked_at ?? now(),
        ]);

        $planLabel = $plan === 'monthly' ? 'mensual' : 'anual';
        $price     = $plan === 'monthly'
            ? number_format($settings->price_monthly_cop, 0, ',', '.')
            : number_format($settings->price_yearly_cop, 0, ',', '.');
        $period = $plan === 'monthly' ? 'mes' : 'año';

        $message = "Hola! Me interesa adquirir la licencia *{$planLabel}* de AI Companion (\${$price} COP/{$period}). "
            . "Mi nombre es {$licenseRequest->name}, mi email es {$licenseRequest->email} "
            . "y mi teléfono es {$licenseRequest->phone}. ¿Cómo procedo?";

        return redirect()->away("https://wa.me/{$number}?text=" . urlencode($message));
    }

    // ─────────────────────────────────────────────
    // POST /api/license/request
    // ─────────────────────────────────────────────
    public function submitRequest(Request $request): JsonResponse
    {
        $user = $request->user();

        // Fix #3: user must be registered (route is behind auth:sanctum, but enforce explicitly)
        // Fix #3: block if already has active license
        if ($user->hasActiveLicense()) {
            return response()->json([
                'error'   => 'already_licensed',
                'message' => 'Ya tienes una licencia activa. No necesitas solicitar una nueva.',
            ], 422);
        }

        // Fix #3: block if already has a pending request
        $existing = $user->licenseRequests()->where('status', 'pending')->latest()->first();
        if ($existing) {
            return response()->json([
                'error'   => 'request_pending',
                'message' => 'Ya tienes una solicitud en revisión. Te notificaremos pronto.',
                'request' => [
                    'id'         => $existing->id,
                    'plan_type'  => $existing->plan_type,
                    'created_at' => $existing->created_at,
                ],
            ], 422);
        }

        $data = $request->validate([
            'name'      => 'required|string|max:100',
            'email'     => 'required|email|max:150',
            'phone'     => 'required|string|max:30',
            'company'   => 'nullable|string|max:100',
            'city'      => 'nullable|string|max:80',
            'plan_type' => 'required|in:monthly,yearly',
        ]);

        // Fix #1 of user_id: user IS authenticated here (auth:sanctum), always set
        $data['user_id'] = $user->id;

        $licenseRequest = LicenseRequest::create($data);

        $settings = LicenseSetting::current();

        try {
            Mail::to($data['email'])->send(new LicenseCatalogMail($licenseRequest, $settings));
            $licenseRequest->update(['catalog_sent_at' => now()]);
        } catch (\Throwable $e) {
            \Log::warning('License catalog email failed', [
                'request_id' => $licenseRequest->id,
                'email'      => $data['email'],
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Solicitud enviada. Revisa tu correo con el catálogo de precios.',
            'request' => [
                'id'              => $licenseRequest->id,
                'plan_type'       => $licenseRequest->plan_type,
                'catalog_sent_at' => $licenseRequest->catalog_sent_at,
            ],
        ], 201);
    }
}
