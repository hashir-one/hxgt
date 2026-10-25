<?php

namespace HxDev\UpiMerchant;

final class RequestSigner
{
    public static function headers(string $token, string $method, string $pathWithQuery, string $rawBody = ''): array
    {
        $parts = explode('_', $token, 4);
        if (count($parts) !== 4 || $parts[0] !== 'upi' || $parts[1] !== 'live') {
            throw new \InvalidArgumentException('Invalid API key');
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(24));
        $canonical = implode("\n", [strtoupper($method), $pathWithQuery, $timestamp, $nonce, hash('sha256', $rawBody)]);

        return ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json', 'X-UPI-Timestamp' => $timestamp, 'X-UPI-Nonce' => $nonce, 'X-UPI-Signature' => hash_hmac('sha256', $canonical, $parts[3])];
    }
}
