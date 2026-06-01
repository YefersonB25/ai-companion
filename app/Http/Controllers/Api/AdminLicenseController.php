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
            'licenses_required' => 'sometimes|boolean',
            'whatsapp_number'   => 'sometimes|string|max:30',
            'price_monthly_cop' => 'sometimes|integer|min:0',
            'price_yearly_cop'  => 'sometimes|integer|min:0',
            'license_features'  => 'sometimes|array',
            'license_features.*' => 'string|max:200',
        ]);

        $settings = LicenseSetting::current();
        $settings->update($data);

        \Log::info('License settings updated', [
            'by'   => $request->user()->email,
            'data' => $data,
        ]);

        return response()->json($settings->fresh());
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/licenses
    // ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $licenses = License::with(['user:id,name,email', 'grantedBy:id,name'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, function ($q) use ($request) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%"));
            })
            ->orderByDesc('created_at')
            ->paginate(30);

        // Auto-expire licenses past their date
        License::active()
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

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
            'expires_at' => 'required|date|after:today',
            'price_paid' => 'nullable|integer|min:0',
            'notes'      => 'nullable|string|max:500',
        ]);

        // Revoke any existing active license for this user
        License::where('user_id', $data['user_id'])
            ->where('status', 'active')
            ->update(['status' => 'revoked']);

        $license = License::create([
            ...$data,
            'starts_at'  => $data['starts_at'] ?? now(),
            'granted_by' => $request->user()->id,
            'status'     => 'active',
        ]);

        $license->load(['user:id,name,email', 'grantedBy:id,name']);

        return response()->json($license, 201);
    }

    // ─────────────────────────────────────────────
    // POST /api/admin/licenses/{license}/revoke
    // ─────────────────────────────────────────────
    public function revoke(License $license, Request $request): JsonResponse
    {
        $license->update(['status' => 'revoked']);

        \Log::info('License revoked', [
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

        $license->update([
            ...$data,
            'status'     => 'active',
            'starts_at'  => now(),
            'granted_by' => $request->user()->id,
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
        $data = $request->validate([
            'expires_at'  => 'required|date|after:today',
            'price_paid'  => 'nullable|integer|min:0',
            'admin_notes' => 'nullable|string|max:500',
        ]);

        $licenseRequest->update([
            'status'      => 'accepted',
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        // Create the license if user is registered
        $license = null;
        if ($licenseRequest->user_id) {
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
        }

        // Enviar email de confirmación con detalles de la licencia
        if ($license) {
            try {
                Mail::to($licenseRequest->email)
                    ->send(new LicenseActivatedMail($license, $licenseRequest));
            } catch (\Throwable $e) {
                \Log::warning('Error enviando email de licencia activada', [
                    'request_id' => $licenseRequest->id,
                    'email'      => $licenseRequest->email,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Solicitud aceptada' . ($license ? ', licencia creada y email enviado.' : '.'),
            'request' => $licenseRequest->fresh(),
            'license' => $license,
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

        return response()->json(['message' => 'Solicitud rechazada.', 'request' => $licenseRequest->fresh()]);
    }

    // ─────────────────────────────────────────────
    // GET /api/admin/license/summary
    // ─────────────────────────────────────────────
    public function summary(): JsonResponse
    {
        $settings = LicenseSetting::current();

        $totalActive   = License::active()->count();
        $totalExpired  = License::expired()->count();
        $totalRevoked  = License::where('status', 'revoked')->count();
        $pendingReqs   = LicenseRequest::where('status', 'pending')->count();
        $expiringWeek  = License::active()
            ->where('expires_at', '<=', now()->addDays(7))
            ->count();

        return response()->json([
            'licenses_required' => $settings->licenses_required,
            'total_active'      => $totalActive,
            'total_expired'     => $totalExpired,
            'total_revoked'     => $totalRevoked,
            'pending_requests'  => $pendingReqs,
            'expiring_week'     => $expiringWeek,
        ]);
    }
}
