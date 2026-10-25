<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use App\Support\TotpService;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function show(Request $request, TotpService $totp)
    {
        abort_if($request->user()->disabled_at !== null, 403);
        $secret = null;
        $qr = null;
        if (! $request->user()->totp_enabled_at) {
            $secret = $request->session()->get('totp_enrollment_secret') ?? $totp->secret();
            $request->session()->put('totp_enrollment_secret', $secret);
            $uri = 'otpauth://totp/'.rawurlencode('HX Pay:'.$request->user()->email).'?'.http_build_query(['secret' => $secret, 'issuer' => 'HX Pay', 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30]);
            $qr = (new Builder(writer: new SvgWriter, data: $uri, size: 220))->build()->getString();
        }

        return response()->view('auth.totp', compact('secret', 'qr'))->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request, TotpService $totp, AuditLogger $audit)
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        abort_if($request->user()->disabled_at !== null, 403);
        if (! $totp->verify($request->user(), $data['code'], $request->session()->get('totp_enrollment_secret'))) {
            return back()->withErrors(['code' => 'The authenticator code is invalid, expired or already used.']);
        }
        $request->session()->forget('totp_enrollment_secret');
        $request->session()->regenerate();
        $request->session()->put(['mfa_user_id' => $request->user()->id, 'mfa_at' => now()->timestamp]);
        $audit->record('auth.second_factor_verified', $request->user()->merchant_id);

        return redirect()->route($request->user()->isAdmin() ? 'admin.dashboard' : 'panel.dashboard');
    }
}
