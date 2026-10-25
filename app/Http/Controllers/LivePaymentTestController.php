<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderService;
use App\Models\Merchant;
use App\Support\Money;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class LivePaymentTestController extends Controller
{
    public function merchantTransfer(Request $request): View
    {
        return $this->transferQr($request, $request->user()->merchant);
    }

    public function adminTransfer(Request $request, Merchant $merchant): View
    {
        return $this->transferQr($request, $merchant);
    }

    private function transferQr(Request $request, Merchant $merchant): View
    {
        abort_unless($merchant->status === 'active', 403, 'Merchant account is not active.');
        $data = $request->validate($this->amountRules());
        $amountMinor = Money::parseInr($data['live_amount']);
        $reference = 'HXTEST'.Str::upper(Str::random(16));
        $upiUri = 'upi://pay?'.http_build_query([
            'pa' => $merchant->upi_vpa,
            'pn' => $merchant->name,
            'am' => number_format($amountMinor / 100, 2, '.', ''),
            'cu' => 'INR',
            'tr' => $reference,
            'tn' => 'Direct transfer test - verify receipt with bank',
        ], '', '&', PHP_QUERY_RFC3986);
        $qr = (new Builder(writer: new SvgWriter, data: $upiUri, size: 280, margin: 12))->build()->getString();

        return view('checkout.transfer-test', compact('merchant', 'amountMinor', 'reference', 'upiUri', 'qr'));
    }

    public function merchant(Request $request, OrderService $orders): RedirectResponse
    {
        return $this->create($request, $request->user()->merchant, $orders);
    }

    public function admin(Request $request, Merchant $merchant, OrderService $orders): RedirectResponse
    {
        return $this->create($request, $merchant, $orders);
    }

    private function create(Request $request, Merchant $merchant, OrderService $orders): RedirectResponse
    {
        abort_unless($merchant->status === 'active', 403, 'Merchant account is not active.');
        $data = $request->validate($this->amountRules() + [
            'request_id' => ['required', 'uuid'],
        ], [
            'live_amount.regex' => 'Enter a rupee amount with up to two decimal places.',
            'live_amount.between' => 'Live tests support a base amount from ₹1 to ₹10.',
        ]);
        $requestId = strtolower($data['request_id']);
        $payload = [
            'merchant_order_id' => 'live-test-'.$requestId,
            'base_amount_minor' => Money::parseInr($data['live_amount']),
            'currency' => 'INR',
            'metadata' => ['purpose' => 'live_test', 'created_by_user_id' => $request->user()->id],
        ];

        try {
            $checkout = $orders->create($merchant, $payload, 'live-test:'.$request->user()->id.':'.$requestId);
        } catch (RuntimeException $exception) {
            $message = match ($exception->getMessage()) {
                'GATEWAY_UNAVAILABLE' => 'Live QR unavailable: a verified capture device with healthy SMS and notification listeners must be online, and the gateway must be accepting payments.',
                'BUSY' => 'All matching amounts are reserved. Try another amount or wait for an earlier order to expire.',
                'IDEMPOTENCY_KEY_REUSED' => 'This test request was already used with a different amount. Reload the preview before starting a new test.',
                'ORDER_COULD_NOT_BE_CREATED', 'INVALID_AMOUNT' => 'The live test could not be created. Reload the preview and try again.',
                default => null,
            };
            if ($message === null) {
                throw $exception;
            }

            return back()->withErrors(['live_amount' => $message])->withInput();
        }

        return redirect()->away($checkout['checkout_url']);
    }

    private function amountRules(): array
    {
        return ['live_amount' => ['required', 'string', 'max:5', 'regex:/\A\d+(?:\.\d{1,2})?\z/', 'numeric', 'between:1,10']];
    }
}
