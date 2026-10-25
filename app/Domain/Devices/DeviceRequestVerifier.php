<?php

namespace App\Domain\Devices;

use App\Models\Device;
use App\Models\DeviceNonce;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class DeviceRequestVerifier
{
    public function verify(Request $request): Device
    {
        $deviceId = (string) $request->header('X-Device-ID');
        $timestamp = (string) $request->header('X-Device-Timestamp');
        $nonce = (string) $request->header('X-Device-Nonce');
        $signature = (string) $request->header('X-Device-Signature');
        $bodyHash = (string) $request->header('X-Device-Body-SHA256');

        if ($deviceId === '' || $timestamp === '' || $nonce === '' || strlen($nonce) > 128 || $signature === '' || $bodyHash === '') {
            throw new UnauthorizedHttpException('Device signature headers are required.');
        }

        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > config('gateway.device.request_clock_skew_seconds')) {
            throw new UnauthorizedHttpException('Device request timestamp is outside the allowed window.');
        }

        $rawBody = $request->getContent();
        $calculatedBodyHash = hash('sha256', $rawBody);
        if (! preg_match('/\A[0-9a-f]{64}\z/', $bodyHash) || ! hash_equals($calculatedBodyHash, strtolower($bodyHash))) {
            throw new UnauthorizedHttpException('Device request body hash mismatch.');
        }

        $device = Device::query()->where('public_id', $deviceId)->whereNull('revoked_at')->first();
        if ($device === null) {
            throw new UnauthorizedHttpException('Unknown or revoked device.');
        }

        $canonical = implode("\n", [strtoupper($request->method()), '/'.ltrim($request->path(), '/'), $timestamp, $nonce, $bodyHash]);
        $signatureBytes = base64_decode($signature, true);
        if ($signatureBytes === false || ! $this->validSignature($device->public_key, $canonical, $signatureBytes)) {
            throw new UnauthorizedHttpException('Invalid device signature.');
        }

        try {
            DeviceNonce::create([
                'device_id' => $device->id,
                'nonce_hash' => hash('sha256', $nonce),
                'expires_at' => CarbonImmutable::createFromTimestamp((int) $timestamp)->addSeconds(config('gateway.device.request_clock_skew_seconds')),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new UnauthorizedHttpException('Device request nonce was already used.');
        }

        return $device;
    }

    private function validSignature(string $publicKey, string $canonical, string $signature): bool
    {
        if (str_contains($publicKey, 'BEGIN PUBLIC KEY')) {
            return openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
        }

        $rawPublicKey = base64_decode($publicKey, true);

        return $rawPublicKey !== false
            && strlen($rawPublicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($signature, $canonical, $rawPublicKey);
    }
}
