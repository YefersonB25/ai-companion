<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\LicenseCatalogMail;
use App\Models\License;
use App\Models\LicenseRequest;
use App\Models\LicenseSetting;
use Illuminate\Http\JsonResponse;
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
