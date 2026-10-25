<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

final class CaptchaVerifier
{
    public function verify(?string $token, string $ip): bool
    {
        if (! config('gateway.claims.captcha_secret') || ! $token) {
            return false;
        }
        try {
            $response = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('gateway.claims.captcha_secret'), 'response' => $token, 'remoteip' => $ip,
            ]);

            return $response->successful() && $response->json('success') === true
                && $response->json('action') === 'payment-claim'
                && in_array($response->json('hostname'), config('gateway.claims.captcha_hosts', []), true);
        } catch (\Throwable) {
            return false;
        }
    }
}
