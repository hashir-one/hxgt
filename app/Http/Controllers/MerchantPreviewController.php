<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MerchantPreviewController extends Controller
{
    public function merchant(Request $request): View
    {
        return $this->preview($request->user()->merchant);
    }

    public function admin(Merchant $merchant): View
    {
        return $this->preview($merchant);
    }

    public function demo(): View
    {
        return $this->preview(new Merchant(['name' => 'Your store']));
    }

    private function preview(Merchant $merchant): View
    {
        $logo = null;
        $path = $merchant->logo_path;
        $disk = Storage::disk('local');
        if ($path && preg_match('/\Amerchant-logos\/[a-zA-Z0-9_-]+\.(png|jpg|jpeg|webp)\z/', $path)
            && $disk->exists($path) && $disk->size($path) <= 1024 * 1024) {
            $mime = $disk->mimeType($path);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $logo = 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
            }
        }

        return view('panel.preview', [
            'title' => 'Preview & test · HX Pay',
            'merchant' => $merchant,
            'logo' => $logo,
            'expiryMinutes' => max(1, min(60, $merchant->settings?->order_expiry_minutes ?? 10)),
            'emailMinimum' => (int) config('gateway.email.minimum_amount_minor'),
            'liveRequestId' => (string) Str::uuid(),
            'liveIssues' => $this->liveIssues($merchant),
            'previewMerchants' => $merchant->exists ? collect() : Merchant::query()->orderBy('name')->limit(20)->get(['id', 'name']),
        ]);
    }

    private function liveIssues(Merchant $merchant): array
    {
        if (! $merchant->exists) {
            return ['Choose a merchant to test a real payment.'];
        }

        $issues = [];
        if ($merchant->status !== 'active') {
            $issues[] = 'This merchant account is suspended.';
        }
        $timeout = $merchant->settings?->heartbeat_timeout_seconds ?? config('gateway.device.heartbeat_timeout_seconds');
        if (! $merchant->devices()->whereNull('revoked_at')->where('attestation_status', 'verified')
            ->where('last_heartbeat_at', '>', now()->subSeconds($timeout))
            ->where('sms_listener_healthy', true)->where('notification_listener_healthy', true)
            ->where('status', 'online')->exists()) {
            $issues[] = 'No verified capture device is online with both bank-alert listeners healthy. Device trust and capture integration must be connected before real auto-confirmation can work.';
        }
        $breaker = $merchant->circuitBreaker;
        if ($breaker?->state === 'open' || ($breaker?->state === 'half_open' && $breaker->probe_order_id !== null)) {
            $issues[] = 'The gateway circuit breaker is restricting new payments.';
        }

        return $issues;
    }
}
