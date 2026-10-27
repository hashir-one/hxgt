<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GatewayHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $request->attributes->set('csp_nonce', $nonce);
        if (app()->environment('production')) {
            $surface = match (true) {
                $request->is('api/device/*') => 'device_domain', $request->is('checkout/*') => 'checkout_domain', $request->is('api/*') => 'api_domain', default => 'admin_domain'
            };
            $expected = parse_url(config('gateway.'.$surface), PHP_URL_HOST);
            abort_unless($request->isSecure() && $expected && strtolower($request->getHost()) === strtolower($expected), 403, 'Secure gateway entry point required');
        }
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-{$nonce}' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($request->is('panel*', 'admin*', 'checkout*', 'security*', 'api/device/v1/pair/recovery')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
