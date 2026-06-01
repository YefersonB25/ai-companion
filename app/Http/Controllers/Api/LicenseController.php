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

        /** @var License|null $license */
        $license = $user->licenses()->active()->latest('expires_at')->first()
            ?? $user->licenses()->latest()->first();

        $pendingRequest = $user->licenseRequests()
            ->where('status', 'pending')
            ->latest()
            ->first();

        return response()->json([
            'licenses_required' => $settings->licenses_required,
            'has_active_license' => $license?->isActive() ?? false,
            'license' => $license ? [
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
    // Registra el clic del botón WhatsApp en el email del catálogo y redirige
    // ─────────────────────────────────────────────
    public function whatsappRedirect(LicenseRequest $licenseRequest, string $plan): RedirectResponse
    {
        abort_unless(in_array($plan, ['monthly', 'yearly']), 404);

        // Actualizar plan si eligió uno distinto al del formulario, y registrar clic
        $licenseRequest->update([
            'plan_type'           => $plan,
            'whatsapp_clicked_at' => $licenseRequest->whatsapp_clicked_at ?? now(),
        ]);

        $settings = LicenseSetting::current();
        $number   = preg_replace('/[^0-9]/', '', $settings->whatsapp_number);

        $planLabel = $plan === 'monthly' ? 'mensual' : 'anual';
        $price     = $plan === 'monthly'
            ? number_format($settings->price_monthly_cop, 0, ',', '.')
            : number_format($settings->price_yearly_cop, 0, ',', '.');
        $period    = $plan === 'monthly' ? 'mes' : 'año';

        $message = "Hola! Me interesa adquirir la licencia *{$planLabel}* de AI Companion (\${$price} COP/{$period}). "
            . "Mi nombre es {$licenseRequest->name}, mi email es {$licenseRequest->email} "
            . "y mi teléfono es {$licenseRequest->phone}. ¿Cómo procedo?";

        $url = "https://wa.me/{$number}?text=" . urlencode($message);

        return redirect()->away($url);
    }

    // ─────────────────────────────────────────────
    // POST /api/license/request
    // ─────────────────────────────────────────────
    public function submitRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:100',
            'email'     => 'required|email|max:150',
            'phone'     => 'required|string|max:30',
            'company'   => 'nullable|string|max:100',
            'city'      => 'nullable|string|max:80',
            'plan_type' => 'required|in:monthly,yearly',
        ]);

        $data['user_id'] = $request->user()?->id;

        $licenseRequest = LicenseRequest::create($data);

        $settings = LicenseSetting::current();

        // Send catalog email
        try {
            Mail::to($data['email'])->send(
                new LicenseCatalogMail($licenseRequest, $settings)
            );
            $licenseRequest->update(['catalog_sent_at' => now()]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to send license catalog email', [
                'email' => $data['email'],
                'error' => $e->getMessage(),
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
