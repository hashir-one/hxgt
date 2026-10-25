<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payments\EmailPaymentMatchingService;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class EmailSignalController extends Controller
{
    public function store(Request $request, EmailPaymentMatchingService $matching)
    {
        $this->verifySignature($request);
        $payload = $request->validate([
            'merchant_id' => ['required', 'integer', 'min:1'],
            'message_id' => ['required', 'string', 'max:512'],
            'sender_email' => ['required', 'email', 'max:255'],
            'subject' => ['nullable', 'string', 'max:512'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reference_number' => ['required', 'digits:12'],
            'occurred_at' => ['required', 'date'],
            'authentication' => ['required', 'array'],
            'authentication.spf' => ['required', 'boolean'],
            'authentication.dkim' => ['required', 'boolean'],
            'authentication.dmarc' => ['required', 'boolean'],
            'parser_rule_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $merchant = Merchant::findOrFail($payload['merchant_id']);

        return response()->json($matching->ingest($merchant, $payload), 202);
    }

    private function verifySignature(Request $request): void
    {
        $secret = (string) config('gateway.email.ingest_secret');
        $timestamp = (string) $request->header('X-Email-Timestamp');
        $nonce = (string) $request->header('X-Email-Nonce');
        $provided = (string) $request->header('X-Email-Signature');

        if ($secret === '' || ! ctype_digit($timestamp) || $nonce === '' || abs(now()->timestamp - (int) $timestamp) > config('gateway.device.request_clock_skew_seconds') || ! str_starts_with($provided, 'sha256=')) {
            throw new UnauthorizedHttpException('Invalid email ingestion signature.');
        }

        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', implode("\n", [$request->method(), '/'.$request->path(), $timestamp, $nonce, hash('sha256', $rawBody)]), $secret);
        if (! hash_equals($expected, substr($provided, 7))) {
            throw new UnauthorizedHttpException('Invalid email ingestion signature.');
        }

        if (! Cache::add('email-ingest:'.$nonce, true, now()->addSeconds(config('gateway.device.request_clock_skew_seconds')))) {
            throw new UnauthorizedHttpException('Email ingestion nonce was already used.');
        }
    }
}
