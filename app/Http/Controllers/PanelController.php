<?php

namespace App\Http\Controllers;

use App\Domain\Devices\ApprovalService;
use App\Domain\Devices\DevicePairingService;
use App\Domain\Devices\GatewayHealthService;
use App\Domain\Orders\ApiCredentialService;
use App\Domain\Payments\PaymentMatchingService;
use App\Domain\Webhooks\WebhookService;
use App\Models\ApiCredential;
use App\Models\Device;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\OtpChallenge;
use App\Models\PaymentSignal;
use App\Models\WebhookDelivery;
use App\Support\AuditLogger;
use App\Support\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PanelController extends Controller
{
    public function dashboard()
    {
        $merchant = auth()->user()->merchant;
        abort_unless($merchant !== null, 403);
        $today = now()->startOfDay();
        $month = now()->startOfMonth();
        $paused = ! app(GatewayHealthService::class)->canAcceptOrders($merchant);
        $total = $merchant->orders()->where('created_at', '>=', $month)->count();

        return view('panel.dashboard', [
            'merchant' => $merchant,
            'todayTotal' => $merchant->orders()->where('status', 'paid')->where('paid_at', '>=', $today)->sum('payable_amount_minor'),
            'monthTotal' => $merchant->orders()->where('status', 'paid')->where('paid_at', '>=', $month)->sum('payable_amount_minor'),
            'pending' => $merchant->orders()->whereIn('status', ['pending', 'detected'])->count(),
            'needsReview' => $merchant->orders()->whereIn('status', ['needs_review', 'late_payment'])->count(),
            'devices' => $merchant->devices()->whereNull('revoked_at')->latest()->get(),
            'recentOrders' => $merchant->orders()->latest()->limit(6)->get(),
            'breaker' => $merchant->circuitBreaker,
            'paused' => $paused,
            'successRate' => $total ? round(100 * $merchant->orders()->where('created_at', '>=', $month)->where('status', 'paid')->count() / $total, 1) : 0,
        ]);
    }

    public function payments(Request $request)
    {
        return view('panel.payments', ['merchant' => auth()->user()->merchant, 'orders' => $this->paymentQuery($request)->latest()->paginate(25)->withQueryString()]);
    }

    private function paymentQuery(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,detected,paid,expired,needs_review,late_payment'], 'order_id' => ['nullable', 'string', 'max:128'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'min_amount_minor' => ['nullable', 'integer', 'min:0'], 'max_amount_minor' => ['nullable', 'integer', 'min:0']]);

        return auth()->user()->merchant->orders()->with('payment')
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($data['order_id'] ?? null, fn ($q, $v) => $q->where('merchant_order_id', 'like', '%'.$v.'%'))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when(isset($data['min_amount_minor']), fn ($q) => $q->where('payable_amount_minor', '>=', $data['min_amount_minor']))
            ->when(isset($data['max_amount_minor']), fn ($q) => $q->where('payable_amount_minor', '<=', $data['max_amount_minor']));
    }

    public function settings()
    {
        return view('panel.settings', ['merchant' => auth()->user()->merchant]);
    }

    public function updateSettings(Request $request, AuditLogger $audit)
    {
        $data = $request->validate(['support_phone' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'], 'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000']]);
        unset($data['logo']);
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('merchant-logos', 'local');
        }
        $merchant = $request->user()->merchant;
        $merchant->update($data);
        $audit->record('merchant.support_updated', $merchant->id, get_class($merchant), $merchant->id);

        return back()->with('status', 'Customer support contact updated.');
    }

    public function payment(Order $order)
    {
        abort_unless($order->merchant_id === auth()->user()->merchant_id, 404);
        $order->load(['payment', 'events', 'disputes']);
        $signals = PaymentSignal::query()
            ->whereHas('device', fn ($query) => $query->where('merchant_id', $order->merchant_id))
            ->where('reference_number_hash', $order->payment?->reference_number_hash ?? 'none')
            ->orderBy('occurred_at')->get();
        $deliveries = WebhookDelivery::query()->where('merchant_id', $order->merchant_id)
            ->whereIn('order_event_id', $order->events->pluck('id'))->latest()->get();

        $refunds = DB::table('refund_records')->where('order_id', $order->id)->latest()->get();
        $acknowledgements = DB::table('event_acknowledgements')->where('merchant_id', $order->merchant_id)->whereIn('order_event_id', $order->events->pluck('id'))->pluck('acknowledged_at', 'order_event_id');

        return view('panel.payment', compact('order', 'signals', 'deliveries', 'refunds', 'acknowledgements'));
    }

    public function devices()
    {
        $merchant = auth()->user()->merchant;

        app(GatewayHealthService::class)->refreshDeviceStates($merchant);

        return view('panel.devices', ['merchant' => $merchant, 'devices' => $merchant->devices()->latest()->get()]);
    }

    public function developer()
    {
        $merchant = auth()->user()->merchant;

        return view('panel.developer', ['merchant' => $merchant, 'keys' => $merchant->apiCredentials()->latest()->get(), 'webhook' => $merchant->webhookEndpoints()->latest()->first()]);
    }

    public function disputes()
    {
        $merchant = auth()->user()->merchant;
        $disputes = Dispute::query()->with('order')->whereHas('order', fn ($query) => $query->where('merchant_id', $merchant->id))->where('status', 'open')->latest()->paginate(25);

        return view('panel.disputes', compact('merchant', 'disputes'));
    }

    public function issueKey(Request $request, ApiCredentialService $keys, AuditLogger $audit)
    {
        $payload = $request->validate(['name' => ['required', 'string', 'max:80'], 'type' => ['required', 'in:create,read'], 'ip_allowlist' => ['nullable', 'string', 'max:1000']]);
        $ips = array_values(array_filter(preg_split('/[\s,]+/', trim($payload['ip_allowlist'] ?? ''))));
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                return back()->withErrors(['ip_allowlist' => 'Enter individual IPv4 or IPv6 addresses.']);
            }
        }
        $scopes = $payload['type'] === 'create' ? ['orders:write'] : ['orders:read', 'events:read', 'events:ack'];
        [$credential, $plainText] = $keys->issue(auth()->user()->merchant, $payload['name'], $payload['type'], $scopes);
        $credential->update(['ip_allowlist' => $ips ?: null]);
        $audit->record('api_key.created', auth()->user()->merchant_id, get_class($credential), $credential->id);

        return back()->with('secret', $plainText);
    }

    public function revokeKey(ApiCredential $credential, AuditLogger $audit)
    {
        abort_unless($credential->merchant_id === auth()->user()->merchant_id, 403);
        $credential->update(['revoked_at' => now()]);
        $audit->record('api_key.revoked', $credential->merchant_id, get_class($credential), $credential->id);

        return back()->with('status', 'API key revoked.');
    }

    public function updateWebhook(Request $request, WebhookService $webhooks, AuditLogger $audit)
    {
        $payload = $request->validate(['url' => ['required', 'url', 'max:2048']]);
        try {
            $webhooks->assertSafeUrl($payload['url']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['url' => $exception->getMessage()])->withInput();
        }
        $secret = Str::random(64);
        $endpoint = auth()->user()->merchant->webhookEndpoints()->updateOrCreate([], [
            'url' => $payload['url'],
            'secret_encrypted' => $secret,
            'status' => 'active',
        ]);
        $audit->record('webhook.updated', $endpoint->merchant_id, get_class($endpoint), $endpoint->id);

        return back()->with('webhook_secret', $secret);
    }

    public function createPairing(Request $request, DevicePairingService $pairings, AuditLogger $audit, OtpService $otp)
    {
        $payload = $request->validate(['slot_number' => ['required', 'integer', 'min:1', 'max:4'], 'otp' => ['required', 'digits:6'], 'replaces_device_id' => ['nullable', 'integer']]);
        $challenge = OtpChallenge::find(session('pairing_challenge_id'));
        abort_unless($challenge && $challenge->user_id === auth()->id() && $challenge->purpose === 'pair_device' && $otp->verify($challenge, $payload['otp']), 422, 'Fresh pairing OTP required');
        session()->forget('pairing_challenge_id');
        try {
            [$pairing, $token] = $pairings->create(auth()->user()->merchant, auth()->user(), $payload['slot_number'], $payload['replaces_device_id'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['slot_number' => $exception->getMessage()])->withInput();
        }
        $audit->record('device.pairing_created', auth()->user()->merchant_id, get_class($pairing), $pairing->id);

        return back()->with('pairing_token', $token)->with('pairing_expires_at', $pairing->expires_at->toIso8601String());
    }

    public function pairingOtp(OtpService $otp)
    {
        $challenge = $otp->issue(auth()->user(), auth()->user()->email, 'pair_device');
        session(['pairing_challenge_id' => $challenge->id]);

        return back()->with('status', 'A pairing code was sent to the owner email.');
    }

    public function requestDeviceApproval(Request $request, ApprovalService $approvals)
    {
        $data = $request->validate(['type' => ['required', 'in:additional_device,migration'], 'target_id' => ['required', 'integer', 'min:1']]);
        if ($data['type'] === 'migration') {
            Device::where('merchant_id', $request->user()->merchant_id)->whereNull('revoked_at')->findOrFail($data['target_id']);
        } else {
            abort_unless(in_array($data['target_id'], [2, 3, 4]), 422);
        }
        $approvals->request($request->user()->merchant, $request->user(), $data['type'], $data['target_id']);

        return back()->with('status', 'Approval requested. Issue a fresh pairing QR after approval.');
    }

    public function revokeDevice(Device $device, AuditLogger $audit)
    {
        abort_unless($device->merchant_id === auth()->user()->merchant_id, 403);
        DB::transaction(function () use ($device): void {
            $locked = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $locked->update(['revoked_at' => now(), 'status' => 'revoked']);
        });
        $audit->record('device.revoked', $device->merchant_id, get_class($device), $device->id);

        return back()->with('status', 'Device revoked immediately.');
    }

    public function requestOtp(OtpService $otp)
    {
        abort_unless(auth()->user()->role === 'owner', 403);

        $challenge = $otp->issue(auth()->user(), auth()->user()->email, 'manual_confirm');
        session(['action_challenge_id' => $challenge->id]);

        return back()->with('status', 'A confirmation code was sent to the owner email.');
    }

    public function confirmDispute(Request $request, PaymentMatchingService $matching, OtpService $otp, AuditLogger $audit, int $dispute)
    {
        $payload = $request->validate(['reference_number' => ['required', 'digits:12'], 'reason' => ['required', 'string', 'max:500'], 'otp' => ['required', 'digits:6']]);
        $record = Dispute::query()->with('order')->findOrFail($dispute);
        abort_unless(auth()->user()->role === 'owner', 403);
        abort_unless($record->order->merchant_id === auth()->user()->merchant_id, 403);
        abort_unless($record->status === 'open', 422, 'DISPUTE_ALREADY_RESOLVED');
        $challenge = OtpChallenge::find(session('action_challenge_id'));
        abort_unless($challenge !== null && $challenge->user_id === auth()->id() && $challenge->purpose === 'manual_confirm' && $otp->verify($challenge, $payload['otp']), 422, 'Fresh OTP required.');
        session()->forget('action_challenge_id');

        try {
            DB::transaction(function () use ($record, $matching, $payload, $audit): void {
                $record = Dispute::query()->lockForUpdate()->findOrFail($record->id);
                abort_unless($record->status === 'open', 422, 'DISPUTE_ALREADY_RESOLVED');
                $matching->manualConfirm($record->order, $payload['reference_number'], $payload['reason']);
                $record->update(['status' => 'resolved', 'merchant_decision' => 'confirmed', 'merchant_reason' => $payload['reason'], 'resolved_at' => now()]);
                $audit->record('payment.manual_confirmed', $record->order->merchant_id, Order::class, $record->order->id, $payload['reason'], ['dispute_id' => $record->id, 'source' => 'merchant_panel']);
            });
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reference_number' => $exception->getMessage()]);
        }

        return back()->with('status', 'Payment manually confirmed and audited.');
    }

    public function rejectDispute(Request $request, Dispute $dispute, AuditLogger $audit)
    {
        abort_unless($dispute->order->merchant_id === $request->user()->merchant_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($dispute, $data, $audit): void {
            $locked = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);
            abort_unless($locked->status === 'open', 422);
            $locked->update(['status' => 'resolved', 'merchant_decision' => 'rejected', 'merchant_reason' => $data['reason'], 'resolved_at' => now()]);
            $audit->record('dispute.rejected', $locked->order->merchant_id, Dispute::class, $locked->id, $data['reason']);
        });

        return back()->with('status', 'Dispute rejected with a recorded reason.');
    }

    public function exportPayments(Request $request)
    {
        $query = $this->paymentQuery($request);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['order_id', 'merchant_order_id', 'amount_minor', 'status', 'confirmation_method', 'created_at', 'paid_at'], escape: '');
            $query->orderBy('id')->chunk(250, function ($orders) use ($handle): void {
                foreach ($orders as $order) {
                    $row = [$order->public_id, $order->merchant_order_id, $order->payable_amount_minor, $order->status, $order->confirmation_method, $order->created_at, $order->paid_at];
                    $row = array_map(fn ($value) => preg_match('/^[\s]*[=+@-]/', (string) $value) ? "'".$value : $value, $row);
                    fputcsv($handle, $row, escape: '');
                }
            });
            fclose($handle);
        }, 'payments.csv', ['Content-Type' => 'text/csv']);
    }
}
