<?php

namespace App\Http\Controllers;

use App\Domain\Devices\GatewayHealthService;
use App\Domain\Payments\PaymentMatchingService;
use App\Models\Order;
use App\Support\CaptchaVerifier;
use App\Support\Money;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CheckoutController extends Controller
{
    public function show(string $token, GatewayHealthService $health)
    {
        $order = $this->order($token);
        $merchant = $order->merchant;
        $locale = request()->query('lang', $merchant->default_locale);
        app()->setLocale(in_array($locale, ['en', 'hi', 'mr'], true) ? $locale : 'en');
        $paused = ! $health->canAcceptOrders($merchant) && in_array($order->status, ['pending', 'detected'], true) && $order->expires_at->isFuture();
        $payable = ! $paused && in_array($order->status, ['pending', 'detected'], true) && $order->expires_at->isFuture();
        $upiUri = $this->upiUri($order);
        $upiApps = [];
        $fallback = rawurlencode(route('checkout.show', ['token' => $token]));
        foreach (['Google Pay' => 'com.google.android.apps.nbu.paisa.user', 'PhonePe' => 'com.phonepe.app', 'Paytm' => 'net.one97.paytm', 'BHIM' => 'in.org.npci.upiapp'] as $name => $package) {
            $upiApps[$name] = str_replace('upi://', 'intent://', $upiUri).'#Intent;scheme=upi;package='.$package.';S.browser_fallback_url='.$fallback.';end';
        }
        $qr = $payable ? (new Builder(writer: new SvgWriter, data: $upiUri, size: 280, margin: 12))->build()->getString() : null;

        return view('checkout.show', compact('order', 'merchant', 'paused', 'payable', 'upiUri', 'upiApps', 'qr'));
    }

    public function logo(string $token)
    {
        $order = $this->order($token);
        $path = $order->merchant->logo_path;
        abort_unless($path && str_starts_with($path, 'merchant-logos/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function status(string $token, GatewayHealthService $health)
    {
        $order = $this->order($token);
        $state = match (true) {
            $order->status === 'paid' => 'confirmed',
            $order->status === 'late_payment' => 'expired',
            $order->status === 'needs_review' => 'needs_review',
            $order->expires_at->isPast() && $order->status !== 'paid' => 'expired',
            ! $health->canAcceptOrders($order->merchant) && in_array($order->status, ['pending', 'detected'], true) => 'gateway_paused',
            $order->status === 'detected' => 'detected',
            default => 'waiting',
        };

        return response()->json([
            'state' => $state,
            'status' => $order->status,
            'expires_at' => $order->expires_at->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'reference_number' => $order->status === 'paid' ? $order->payment?->reference_number_normalized : null,
        ]);
    }

    public function events(string $token, GatewayHealthService $health)
    {
        abort_unless(config('gateway.checkout_stream_enabled'), 404);
        $this->order($token);

        return response()->stream(function () use ($token, $health): void {
            $deadline = microtime(true) + 20;
            do {
                $data = $this->status($token, $health)->getData(true);
                echo "event: payment\ndata: ".json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                } flush();
                if (connection_aborted() || in_array($data['state'], ['confirmed', 'expired', 'needs_review'], true)) {
                    break;
                }
                sleep(2);
            } while (microtime(true) < $deadline);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-store', 'X-Accel-Buffering' => 'no']);
    }

    public function claim(Request $request, string $token, PaymentMatchingService $matching)
    {
        $order = $this->order($token);
        $payload = $request->validate(['reference_number' => ['required', 'digits:12'], 'cf-turnstile-response' => ['required', 'string', 'max:4096']]);
        abort_unless(app(CaptchaVerifier::class)->verify($payload['cf-turnstile-response'], $request->ip()), 422, 'Please complete the security check.');
        $allowed = DB::transaction(function () use ($order): bool {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->claim_attempts >= config('gateway.claims.max_attempts')) {
                return false;
            }
            $locked->increment('claim_attempts');

            return true;
        });
        if (! $allowed) {
            return response()->json(['status' => 'rate_limited', 'message' => 'Please contact the merchant.'], 429);
        }

        $reference = Money::assertReference($payload['reference_number']);

        return response()->json($matching->resolveClaim($order, $reference));
    }

    private function order(string $token): Order
    {
        return Order::query()->with(['merchant', 'payment'])->where('checkout_token_hash', hash('sha256', $token))->firstOrFail();
    }

    private function upiUri(Order $order): string
    {
        return 'upi://pay?'.http_build_query([
            'pa' => $order->merchant->upi_vpa,
            'pn' => $order->merchant->name,
            'am' => number_format($order->payable_amount_minor / 100, 2, '.', ''),
            'cu' => $order->currency,
            'tn' => 'Order '.$order->merchant_order_id,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
