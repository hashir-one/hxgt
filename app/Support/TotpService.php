<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        $bits = '';
        foreach (unpack('C*', random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($chunk) => self::ALPHABET[bindec($chunk)], str_split($bits, 5)));
    }

    public function code(string $secret, int $counter): string
    {
        $bits = '';
        foreach (str_split($secret) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new \InvalidArgumentException('Invalid TOTP secret');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $digest = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($digest[19]) & 15;
        $value = unpack('N', substr($digest, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function verify(User $user, string $code, ?string $enrollmentSecret = null): bool
    {
        return DB::transaction(function () use ($user, $code, $enrollmentSecret): bool {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $secret = $locked->totp_secret_encrypted ?? $enrollmentSecret;
            if ($secret === null || ! preg_match('/^\d{6}$/', $code)) {
                return false;
            }
            $counter = intdiv(now()->timestamp, 30);
            foreach ([$counter, $counter - 1, $counter + 1] as $candidate) {
                if ($candidate > ($locked->totp_last_counter ?? -1) && hash_equals($this->code($secret, $candidate), $code)) {
                    $locked->forceFill(['totp_secret_encrypted' => $secret, 'totp_enabled_at' => $locked->totp_enabled_at ?? now(), 'totp_last_counter' => $candidate])->save();

                    return true;
                }
            }

            return false;
        });
    }
}
