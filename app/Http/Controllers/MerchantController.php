<?php

namespace App\Http\Controllers;

use App\Domain\Devices\GatewayHealthService;
use App\Http\Requests\MerchantRequest;
use App\Models\AuditLog;
use App\Models\Merchant;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MerchantController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:active,suspended']]);
        $merchants = Merchant::query()->withCount(['orders', 'devices'])
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$term.'%')->orWhere('slug', 'like', '%'.$term.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()->paginate(15)->withQueryString();

        return view('admin.merchants.index', compact('merchants'));
    }

    public function create(): View
    {
        return view('admin.merchants.form', ['merchant' => new Merchant]);
    }

    public function store(MerchantRequest $request, GatewayHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $merchant = DB::transaction(function () use ($data, $health, $audit): Merchant {
            $merchant = Merchant::create(collect($data)->only(['name', 'slug', 'upi_vpa', 'support_phone', 'return_url_allowlist', 'default_locale'])->all());
            $owner = $merchant->users()->create([
                'name' => $data['owner_name'], 'email' => $data['owner_email'],
                'role' => 'owner', 'password' => Str::random(64),
            ]);
            $health->settings($merchant)->update(collect($data)->only(['order_expiry_minutes', 'auto_confirm_order_cap_minor', 'auto_confirm_day_cap_minor', 'heartbeat_timeout_seconds', 'healthy_heartbeats_to_resume', 'breaker_consecutive_threshold', 'breaker_rolling_threshold', 'breaker_window_minutes', 'breaker_cooldown_minutes', 'dispute_escalation_hours'])->all());
            $health->breaker($merchant);
            $audit->record('merchant.created', $merchant->id, Merchant::class, $merchant->id, metadata: ['owner_id' => $owner->id]);

            return $merchant;
        });

        return redirect()->route('admin.merchants.show', $merchant)->with('status', 'Merchant created. The owner can request an email code on the login page. Payments remain paused until a verified capture device is healthy.');
    }

    public function show(Merchant $merchant): View
    {
        $merchant->load(['users', 'devices', 'apiCredentials', 'settings', 'circuitBreaker', 'webhookEndpoints'])->loadCount('orders');
        $audit = AuditLog::where('merchant_id', $merchant->id)->latest('created_at')->limit(20)->get();

        return view('admin.merchants.show', compact('merchant', 'audit'));
    }

    public function edit(Merchant $merchant): View
    {
        $merchant->load('settings');

        return view('admin.merchants.form', compact('merchant'));
    }

    public function update(MerchantRequest $request, Merchant $merchant, AuditLogger $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $merchant, $audit): void {
            $data = $request->validated();
            $merchant->update(collect($data)->only(['name', 'slug', 'upi_vpa', 'support_phone', 'return_url_allowlist', 'default_locale'])->all());
            app(GatewayHealthService::class)->settings($merchant)->update(collect($data)->only(['order_expiry_minutes', 'auto_confirm_order_cap_minor', 'auto_confirm_day_cap_minor', 'heartbeat_timeout_seconds', 'healthy_heartbeats_to_resume', 'breaker_consecutive_threshold', 'breaker_rolling_threshold', 'breaker_window_minutes', 'breaker_cooldown_minutes', 'dispute_escalation_hours'])->all());
            $audit->record('merchant.updated', $merchant->id, Merchant::class, $merchant->id);
        });

        return redirect()->route('admin.merchants.show', $merchant)->with('status', 'Merchant settings updated.');
    }
}
