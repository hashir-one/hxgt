<?php

namespace App\Http\Controllers;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function requestCode(Request $request, OtpService $otp)
    {
        $payload = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($payload['email']);
        $rateKey = 'login:'.sha1($email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return back()->withErrors(['email' => 'Too many requests. Please try again later.']);
        }
        RateLimiter::hit($rateKey, now()->addMinutes(10));

        $user = User::query()->where('email', $email)->whereIn('role', ['owner', 'admin', 'staff'])->whereNull('disabled_at')->first();

        $request->session()->forget('login_challenge_id');
        if ($user !== null) {
            $challenge = $otp->issue($user, $email, 'login');
            $request->session()->put('login_challenge_id', $challenge->id);
        }

        return redirect()->route('login')->with('status', 'If that account exists, a verification code has been sent.');
    }

    public function verifyCode(Request $request, OtpService $otp)
    {
        $payload = $request->validate(['code' => ['required', 'digits:6']]);
        $verifyKey = 'login-verify:'.$request->session()->getId();
        if (RateLimiter::tooManyAttempts($verifyKey, 10)) {
            return back()->withErrors(['code' => 'Too many attempts. Please request a new code.']);
        }
        RateLimiter::hit($verifyKey, now()->addMinutes(10));
        $challenge = OtpChallenge::query()->with('user')->find($request->session()->get('login_challenge_id'));

        if ($challenge === null || ! $otp->verify($challenge, $payload['code']) || $challenge->purpose !== 'login' || $challenge->user === null || $challenge->user->disabled_at !== null) {
            return back()->withErrors(['code' => 'The code is invalid or expired.']);
        }

        $request->session()->forget(['login_challenge_id', 'mfa_user_id', 'mfa_at', 'totp_enrollment_secret']);
        Auth::login($challenge->user);
        app(AuditLogger::class)->record('auth.login', $challenge->user->merchant_id);
        $challenge->user->update(['last_login_at' => now()]);
        $request->session()->regenerate();

        if ($challenge->user->isAdmin() || $challenge->user->totp_enabled_at) {
            return redirect()->route('security.totp');
        }

        return redirect()->intended(route('panel.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
