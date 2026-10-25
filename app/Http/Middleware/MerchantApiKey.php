<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class MerchantApiKey
{
    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $authorization = (string) $request->header('Authorization');
        if (! str_starts_with($authorization, 'Bearer ')) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        $token = trim(substr($authorization, 7));
        $parts = explode('_', $token, 4);
        if (count($parts) !== 4 || $parts[0] !== 'upi' || $parts[1] !== 'live') {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        [$prefix, $secret] = [$parts[2], $parts[3]];
        $credential = ApiCredential::query()->with('merchant')->where('key_prefix', $prefix)->first();
        if ($credential === null || ! $credential->isUsable() || ! Hash::check($secret, $credential->secret_hash)) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        if ($credential->merchant === null || $credential->merchant->status !== 'active') {
            return response()->json(['error' => 'MERCHANT_SUSPENDED'], 403);
        }

        if ($credential->ip_allowlist !== null && ! in_array($request->ip(), $credential->ip_allowlist, true)) {
            return response()->json(['error' => 'IP_NOT_ALLOWED'], 403);
        }

        if ($requiredScope !== null && ! in_array($requiredScope, $credential->scopes, true)) {
            return response()->json(['error' => 'FORBIDDEN'], 403);
        }

        if ($requiredScope === 'orders:write' && $credential->type !== 'create') {
            return response()->json(['error' => 'CREATE_KEY_REQUIRED'], 403);
        }

        if (in_array($requiredScope, ['orders:read', 'events:read', 'events:ack'], true) && $credential->type !== 'read') {
            return response()->json(['error' => 'READ_KEY_REQUIRED'], 403);
        }

        $timestamp = (string) $request->header('X-UPI-Timestamp');
        $nonce = (string) $request->header('X-UPI-Nonce');
        $signature = (string) $request->header('X-UPI-Signature');
        $secret = $credential->signing_secret_encrypted;
        $canonical = implode("\n", [strtoupper($request->method()), $request->getRequestUri(), $timestamp, $nonce, hash('sha256', $request->getContent())]);
        if (! $secret || ! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > 300 || strlen($nonce) < 16 || strlen($nonce) > 128
            || ! hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
            return response()->json(['error' => 'REQUEST_SIGNATURE_REQUIRED'], 401);
        }
        $inserted = DB::table('api_nonces')->insertOrIgnore([
            'api_credential_id' => $credential->id, 'nonce_hash' => hash('sha256', $nonce), 'expires_at' => now()->addMinutes(10),
        ]);
        if (! $inserted) {
            return response()->json(['error' => 'REQUEST_REPLAYED'], 401);
        }

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('merchant', $credential->merchant);
        $request->attributes->set('apiCredential', $credential);

        return $next($request);
    }
}
