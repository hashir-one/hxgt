<?php

namespace App\Http\Controllers;

use App\Domain\Devices\ApprovalService;
use App\Domain\Devices\GatewayHealthService;
use App\Models\CircuitBreaker;
use App\Models\Device;
use App\Models\DevicePairing;
use App\Models\Merchant;
use App\Models\ParserRule;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard()
    {
        return view('admin.dashboard', [
            'merchants' => Merchant::withCount(['orders', 'devices'])->latest()->get(),
            'pendingPairings' => DevicePairing::where('approval_status', 'pending')->with('merchant')->latest()->get(),
            'devices' => Device::with('merchant')->where('attestation_status', '!=', 'verified')->latest()->get(),
            'breakers' => CircuitBreaker::with('merchant')->whereIn('state', ['open', 'half_open'])->latest()->get(),
        ]);
    }

    public function approveDevice(Device $device, AuditLogger $audit)
    {
        abort(422, 'Hardware attestation verification must be integrated with the Android capture client. Manual trust overrides are disabled.');
    }

    public function approvePairing(DevicePairing $pairing, AuditLogger $audit)
    {
        abort(422, 'Use the operations approval queue, then request a fresh pairing QR.');
    }

    public function suspend(Merchant $merchant, AuditLogger $audit)
    {
        $merchant->update(['status' => 'suspended']);
        $audit->record('merchant.suspended', $merchant->id, get_class($merchant), $merchant->id);

        return back()->with('status', 'Merchant suspended.');
    }

    public function activate(Merchant $merchant, AuditLogger $audit)
    {
        $merchant->update(['status' => 'active']);
        $audit->record('merchant.reactivated', $merchant->id, get_class($merchant), $merchant->id);

        return back()->with('status', 'Merchant reactivated.');
    }

    public function activateParserRule(Request $request, ParserRule $rule, AuditLogger $audit)
    {
        DB::transaction(function () use ($rule, $audit): void {
            ParserRule::query()->where('source', $rule->source)->orderBy('id')->lockForUpdate()->get()->each->update(['status' => 'retired']);
            $rule->update(['status' => 'active', 'effective_at' => now()]);
            $audit->record('parser_rule.activated', null, get_class($rule), $rule->id);
        });

        return back()->with('status', 'Parser rule activated.');
    }

    public function reopenBreaker(CircuitBreaker $breaker, GatewayHealthService $health, AuditLogger $audit)
    {
        abort_unless(in_array($breaker->state, ['open', 'half_open'], true), 422);
        app(ApprovalService::class)->request($breaker->merchant, auth()->user(), 'breaker_reopen', $breaker->id);

        return back()->with('status', 'Your approval is recorded. A second administrator must approve reopening.');
    }
}
