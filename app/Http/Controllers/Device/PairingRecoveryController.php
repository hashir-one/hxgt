<?php

namespace App\Http\Controllers\Device;

use App\Domain\Devices\PairingKeyRegistry;
use App\Domain\Devices\PendingKeyRecovery;
use App\Http\Controllers\Controller;
use App\Models\OtpChallenge;
use App\Support\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PairingRecoveryController extends Controller
{
    public function otp(Request $request, OtpService $otp): JsonResponse|RedirectResponse
    {
        $challenge = $otp->issue($request->user(), $request->user()->email, 'recover_device_key');
        $request->session()->put('pairing_recovery_otp_id', $challenge->id);

        return $request->expectsJson()
            ? response()->json(['status' => 'code_sent'], 202)
            : back()->with('status', 'A recovery code was sent to the owner email.');
    }

    public function issue(Request $request, OtpService $otp, PendingKeyRecovery $recovery): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:8192'],
            'otp' => ['required', 'digits:6'],
        ]);
        if (PairingKeyRegistry::material($data['public_key']) === null) {
            throw ValidationException::withMessages(['public_key' => 'A P-256 public key PEM is required.']);
        }
        $recovery->origin();
        $challenge = OtpChallenge::find($request->session()->get('pairing_recovery_otp_id'));
        abort_unless($challenge && $challenge->user_id === $request->user()->id
            && $challenge->purpose === 'recover_device_key' && $otp->verify($challenge, $data['otp']),
            422, 'Fresh recovery OTP required');
        $request->session()->forget('pairing_recovery_otp_id');
        $payload = $recovery->issue($request->user(), $data['public_key']);

        return $request->expectsJson()
            ? response()->json($payload, 201)
            : back()->with('pairing_recovery_challenge', $payload);
    }

    public function resolve(Request $request, PendingKeyRecovery $recovery): JsonResponse
    {
        $data = $request->validate([
            'recovery_id' => ['required', 'uuid'],
            'signature' => ['required', 'string', 'max:256'],
        ]);

        return response()->json($recovery->resolve($data['recovery_id'], $data['signature']))
            ->header('Cache-Control', 'no-store, private');
    }
}
