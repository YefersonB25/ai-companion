<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LicenseActivatedMail;
use App\Models\License;
use App\Models\LicenseRequest;
use App\Models\LicenseSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminLicenseController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/admin/license/settings
    // ─────────────────────────────────────────────
    public function settings(): JsonResponse
    {
        return response()->json(LicenseSetting::current());
    }

    // ─────────────────────────────────────────────
    // PUT /api/admin/license/settings
    // ─────────────────────────────────────────────
    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'licenses_required'  => 'sometimes|boolean',
            // Fix #26: validate WhatsApp number format
            'whatsapp_number'    => ['sometimes', 'string', 'max:20', 'regex:/^[0-9]{7,15}$/'],
            'price_monthly_cop'  => 'sometimes|integer|min:0',
            'price_yearly_cop'   => 'sometimes|integer|min:0',
            'license_features'   => 'sometimes|array',
            'license_features.*' => 'string|max:200',
        ]);

        $settings = LicenseSetting::current();
        $settings->update($data);

        Log::info('License settings updated', ['by' => $request->user()->email, 'data' => $data]);

        return response()->json($settings->fresh());
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/licenses
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        // Fix #10: remove auto-expire from index — scope already handles it correctly
        $licenses = License::with(['user:id,name,email', 'grantedBy:id,name'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, function ($q) use ($request) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%"));
            })
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($licenses);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/licenses
    // ─────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'    => 'required|exists:users,id',
            'type'       => 'required|in:monthly,yearly,custom',
            'starts_at'  => 'sometimes|date',
            // Fix #17: expires_at must be after starts_at
            'expires_at' => 'required|date|after:' . ($request->starts_at ?? 'today'),
            'price_paid' => 'nullable|integer|min:0',
            'notes'      => 'nullable|string|max:500',
        ]);

        $startsAt = $data['starts_at'] ?? now();

        // Revoke existing active license
        License::where('user_id', $data['user_id'])->where('status', 'active')
            ->update(['status' => 'revoked']);

        $license = License::create([
            ...$data,
            'starts_at'  => $startsAt,
            'granted_by' => $request->user()->id,
            'status'     => 'active',
        ]);

        $license->load(['user:id,name,email', 'grantedBy:id,name']);

        // Fix #18: log grant action
        Log::info('License granted', [
            'by'         => $request->user()->email,
            'license_id' => $license->id,
            'user_id'    => $license->user_id,
            'type'       => $license->type,
            'expires_at' => $license->expires_at,
        ]);

        return response()->json($license, 201);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/licenses/{license}/revoke
    // ─────────────────────────────────────────────
    public function revoke(License $license, Request $request): JsonResponse
    {
        $license->update(['status' => 'revoked']);

        Log::info('License revoked', [
            'by'         => $request->user()->email,
            'license_id' => $license->id,
            'user_id'    => $license->user_id,
        ]);

        return response()->json(['message' => 'Licencia revocada.', 'license' => $license]);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/licenses/{license}/renew
    // ─────────────────────────────────────────────
    public function renew(License $license, Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'       => 'sometimes|in:monthly,yearly,custom',
            'expires_at' => 'required|date|after:today',
            'price_paid' => 'nullable|integer|min:0',
            'notes'      => 'nullable|string|max:500',
        ]);

        $previous = ['expires_at' => $license->expires_at, 'type' => $license->type];

        $license->update([
            ...$data,
            'status'     => 'active',
            'starts_at'  => now(),
            'granted_by' => $request->user()->id,
        ]);

        // Fix #18: log renewal with previous state
        Log::info('License renewed', [
            'by'         => $request->user()->email,
            'license_id' => $license->id,
            'user_id'    => $license->user_id,
            'previous'   => $previous,
            'new_expiry' => $data['expires_at'],
        ]);

        return response()->json(['message' => 'Licencia renovada.', 'license' => $license->fresh()]);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/license-requests
    // ─────────────────────────────────────────────
    public function requests(Request $request): JsonResponse
    {
        $requests = LicenseRequest::with('user:id,name,email')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('created_at')
            ->paginate(30);

        return response()->json($requests);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/license-requests/{licenseRequest}/accept
    // ─────────────────────────────────────────────
    public function acceptRequest(LicenseRequest $licenseRequest, Request $request): JsonResponse
    {
        // Fix #5: block accepting if request has no linked user
        if (! $licenseRequest->user_id) {
            return response()->json([
                'error'   => 'no_user',
                'message' => 'Esta solicitud no está vinculada a ningún usuario registrado. '
                    . 'El solicitante debe registrarse en la app antes de poder activar la licencia. '
                    . 'Usa "Otorgar licencia" manualmente una vez que se registre.',
            ], 422);
        }

        // Fix #14: verify user still exists
        $user = User::find($licenseRequest->user_id);
        if (! $user) {
            return response()->json([
                'error'   => 'user_not_found',
                'message' => 'El usuario asociado a esta solicitud ya no existe en el sistema.',
            ], 422);
        }

        $data = $request->validate([
            'expires_at'  => 'required|date|after:today',
            'price_paid'  => 'nullable|integer|min:0',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $licenseRequest->update([
            'status'      => 'accepted',
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        // Revoke existing active license and create new one
        License::where('user_id', $licenseRequest->user_id)
            ->where('status', 'active')
            ->update(['status' => 'revoked']);

        $license = License::create([
            'user_id'    => $licenseRequest->user_id,
            'type'       => $licenseRequest->plan_type,
            'status'     => 'active',
            'starts_at'  => now(),
            'expires_at' => $data['expires_at'],
            'granted_by' => $request->user()->id,
            'price_paid' => $data['price_paid'] ?? null,
            'notes'      => "Solicitud #{$licenseRequest->id} aceptada.",
        ]);

        Log::info('License request accepted', [
            'by'         => $request->user()->email,
            'request_id' => $licenseRequest->id,
            'user_id'    => $licenseRequest->user_id,
            'license_id' => $license->id,
            'expires_at' => $data['expires_at'],
        ]);

        // Send activation email
        try {
            Mail::to($licenseRequest->email)
                ->send(new LicenseActivatedMail($license, $licenseRequest));
        } catch (\Throwable $e) {
            Log::warning('License activation email failed', [
                'request_id' => $licenseRequest->id,
                'email'      => $licenseRequest->email,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Solicitud aceptada, licencia creada y email enviado.',
            'request' => $licenseRequest->fresh(),
            'license' => $license->load('user:id,name,email'),
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/license-requests/{licenseRequest}/reject
    // ─────────────────────────────────────────────
    public function rejectRequest(LicenseRequest $licenseRequest, Request $request): JsonResponse
    {
        $data = $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $licenseRequest->update([
            'status'      => 'rejected',
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        Log::info('License request rejected', [
            'by'         => $request->user()->email,
            'request_id' => $licenseRequest->id,
        ]);

        return response()->json(['message' => 'Solicitud rechazada.', 'request' => $licenseRequest->fresh()]);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/license/summary
    // ─────────────────────────────────────────────
    public function summary(): JsonResponse
    {
        $settings = LicenseSetting::current();

        return response()->json([
            'licenses_required' => $settings->licenses_required,
            'total_active'      => License::active()->count(),
            'total_expired'     => License::expired()->count(),
            'total_revoked'     => License::where('status', 'revoked')->count(),
            'pending_requests'  => LicenseRequest::where('status', 'pending')->count(),
            // Fix #27: separate expiring today vs this week
            'expiring_today'    => License::active()->whereDate('expires_at', today())->count(),
            'expiring_week'     => License::active()->where('expires_at', '<=', now()->addDays(7))->count(),
        ]);
    }
}
