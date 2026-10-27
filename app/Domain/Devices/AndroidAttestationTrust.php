<?php

namespace App\Domain\Devices;

use Illuminate\Support\Facades\Http;
use Throwable;

final class AndroidAttestationTrust
{
    public const ROOTS_URL = 'https://android.googleapis.com/attestation/root';

    public const STATUS_URL = 'https://android.googleapis.com/attestation/status';

    /** No cached fallback: availability and freshness are required for every pairing. */
    public function fetch(): array
    {
        $rootResponse = $this->fetchJson(self::ROOTS_URL, 65536);
        $statusResponse = $this->fetchJson(self::STATUS_URL, 1048576);
        $roots = $rootResponse['data'];
        $status = $statusResponse['data'];
        if (! is_array($roots) || ! array_is_list($roots) || count($roots) < 1 || count($roots) > 16
            || ! $status instanceof \stdClass || ! isset($status->entries) || ! $status->entries instanceof \stdClass) {
            throw new AttestationFailure('TRUST_DATA_INVALID', 503);
        }
        foreach ($roots as $root) {
            if (! is_string($root) || strlen($root) > 16384 || ! str_starts_with($root, '-----BEGIN CERTIFICATE-----') || @openssl_x509_read($root) === false) {
                throw new AttestationFailure('TRUST_DATA_INVALID', 503);
            }
        }
        $revoked = [];
        foreach ($status->entries as $serial => $entry) {
            if (! preg_match('/\A[0-9a-fA-F]{1,80}\z/', (string) $serial) || ! $entry instanceof \stdClass || ! is_string($entry->status ?? null)
                || strlen($entry->status) < 1 || strlen($entry->status) > 32) {
                throw new AttestationFailure('TRUST_DATA_INVALID', 503);
            }
            // SUSPENDED and future/unknown listed statuses also fail closed.
            $revoked[] = strtolower(ltrim((string) $serial, '0')) ?: '0';
        }

        return ['roots' => $roots, 'revoked_serials' => $revoked, 'valid_until' => min($rootResponse['valid_until'], $statusResponse['valid_until'])];
    }

    private function fetchJson(string $url, int $limit): mixed
    {
        try {
            $response = Http::connectTimeout(3)->timeout(10)->acceptJson()
                ->withOptions(['allow_redirects' => false, 'stream' => true])->get($url);
            if ($response->status() !== 200) {
                throw new AttestationFailure('TRUST_UNAVAILABLE', 503);
            }
            $parsedDate = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC7231, $response->header('Date'));
            $date = $parsedDate === false ? false : $parsedDate->getTimestamp();
            $ageHeader = $response->header('Age');
            $age = $ageHeader === '' ? 0 : filter_var($ageHeader, FILTER_VALIDATE_INT);
            $matched = preg_match('/(?:^|[,\s])max-age=(\d+)(?:,|\s|$)/i', $response->header('Cache-Control'), $matches);
            $maximum = min(86400, (int) config('attestation.trust_max_age_seconds'), (int) ($matches[1] ?? 0));
            if ($date === false || $age === false || $age < 0 || ! $matched || $maximum < 1
                || $date > now()->timestamp + 300 || max($age, now()->timestamp - $date) >= $maximum) {
                throw new AttestationFailure('TRUST_STALE', 503);
            }
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) <= $limit) {
                $body .= $stream->read(min(8192, $limit + 1 - strlen($body)));
            }
            $stream->close();
            if (strlen($body) > $limit) {
                throw new AttestationFailure('TRUST_DATA_INVALID', 503);
            }

            $validUntil = min($date + $maximum, now()->timestamp + $maximum - $age);
            if ($validUntil <= now()->timestamp) {
                throw new AttestationFailure('TRUST_STALE', 503);
            }

            return ['data' => json_decode($body, false, 16, JSON_THROW_ON_ERROR), 'valid_until' => $validUntil];
        } catch (AttestationFailure $e) {
            throw $e;
        } catch (Throwable) {
            // Never propagate response bodies or exception traces.
            throw new AttestationFailure('TRUST_UNAVAILABLE', 503);
        }
    }
}
