<?php

namespace App\Support;

use App\Mail\OtpMail;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

final class OtpService
{
    public function issue(?User $user, string $email, string $purpose, array $context = []): OtpChallenge
    {
        $code = (string) random_int(100000, 999999);
        OtpChallenge::query()
            ->where('email', strtolower($email))
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $challenge = OtpChallenge::create([
            'user_id' => $user?->id,
            'email' => strtolower($email),
            'purpose' => $purpose,
            'challenge_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('gateway.security.otp_ttl_minutes')),
            'context' => $context,
        ]);

        Mail::to($email)->queue(new OtpMail(
            code: $code,
            purpose: $purpose,
            ttlMinutes: config('gateway.security.otp_ttl_minutes'),
        ));

        return $challenge;
    }

    public function verify(OtpChallenge $challenge, string $code): bool
    {
        return DB::transaction(function () use ($challenge, $code): bool {
            $locked = OtpChallenge::query()->lockForUpdate()->find($challenge->id);
            if ($locked === null || $locked->consumed_at !== null || $locked->expires_at->isPast() || $locked->attempt_count >= 5) {
                return false;
            }

            $locked->increment('attempt_count');
            if (! Hash::check($code, $locked->challenge_hash)) {
                return false;
            }

            $locked->update(['consumed_at' => now()]);

            return true;
        });
    }
}
