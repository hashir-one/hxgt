@extends('layouts.app')
@section('content')
<a class="text-sm text-muted" href="{{ $merchant->exists ? route('admin.merchants.show', $merchant) : route('admin.merchants.index') }}">← Back to merchants</a>
<div class="page-heading"><div><p class="eyebrow">Merchant accounts</p><h1>{{ $merchant->exists ? 'Edit merchant' : 'Add a merchant' }}</h1><p>Set up the business, owner access and payment limits.</p></div><span class="status-pill bg-field text-muted">{{ $merchant->exists ? 'Account settings' : 'New account' }}</span></div>
<form method="post" action="{{ $merchant->exists ? route('admin.merchants.update', $merchant) : route('admin.merchants.store') }}" class="grid gap-6 xl:grid-cols-[1fr_300px]">
    @csrf @if($merchant->exists) @method('put') @endif
    <div class="space-y-6">
        <section class="surface rounded-2xl p-6"><p class="eyebrow">01 / Business</p><h2 class="mt-2 text-xl font-semibold">Where payments go</h2><div class="mt-6 grid gap-5 sm:grid-cols-2">
            <label class="field-label">Business name<input class="field" name="name" required maxlength="120" value="{{ old('name', $merchant->name) }}" placeholder="Acme Store" autocomplete="organization"></label>
            <label class="field-label">Account slug<input class="field" name="slug" required maxlength="100" value="{{ old('slug', $merchant->slug) }}" placeholder="acme-store"><span class="field-help">Lowercase letters, numbers and hyphens.</span></label>
            <label class="field-label">UPI ID<input class="field" name="upi_vpa" required value="{{ old('upi_vpa', $merchant->upi_vpa) }}" placeholder="acme@kotak" spellcheck="false"><span class="field-help">Confirm this ID with the merchant before taking payments.</span></label>
            <label class="field-label">Customer support number<input class="field" name="support_phone" type="tel" required value="{{ old('support_phone', $merchant->support_phone) }}" placeholder="+919876543210" autocomplete="tel"></label>
            <label class="field-label sm:col-span-2">Allowed return domains<textarea class="field" name="return_domains" rows="3" placeholder="shop.example.com">{{ old('return_domains', implode("\n", $merchant->return_url_allowlist ?? [])) }}</textarea><span class="field-help">One exact domain per line. No https://, paths or wildcards. Leave blank to disable checkout redirects.</span></label>
        </div></section>
        @unless($merchant->exists)
        <section class="surface rounded-2xl p-6"><p class="eyebrow">02 / Owner access</p><h2 class="mt-2 text-xl font-semibold">Who manages this account</h2><div class="mt-6 grid gap-5 sm:grid-cols-2">
            <label class="field-label">Owner name<input class="field" name="owner_name" required maxlength="120" value="{{ old('owner_name') }}" autocomplete="name"></label>
            <label class="field-label">Owner email<input class="field" name="owner_email" type="email" required maxlength="255" value="{{ old('owner_email') }}" autocomplete="email"><span class="field-help">The owner signs in with an email code. Use a new account email.</span></label>
        </div></section>
        @endunless
        <section class="surface rounded-2xl p-6"><p class="eyebrow">{{ $merchant->exists ? '02' : '03' }} / Payment limits</p><h2 class="mt-2 text-xl font-semibold">Automatic confirmation</h2><p class="mt-2 text-sm text-muted">Payments over these limits go to the owner's review queue.</p><div class="mt-6 grid gap-5 sm:grid-cols-3">
            <label class="field-label">Order expiry (minutes)<input class="field" type="number" name="order_expiry_minutes" min="1" max="60" required value="{{ old('order_expiry_minutes', $merchant->settings?->order_expiry_minutes ?? 10) }}"></label>
            <label class="field-label">Per order (paise)<input class="field" type="number" name="auto_confirm_order_cap_minor" min="1" max="100000000" required value="{{ old('auto_confirm_order_cap_minor', $merchant->settings?->auto_confirm_order_cap_minor ?? 100000) }}"><span class="field-help">100,000 paise = ₹1,000</span></label>
            <label class="field-label">Per day (paise)<input class="field" type="number" name="auto_confirm_day_cap_minor" min="1" max="1000000000" required value="{{ old('auto_confirm_day_cap_minor', $merchant->settings?->auto_confirm_day_cap_minor ?? 1000000) }}"><span class="field-help">1,000,000 paise = ₹10,000</span></label>
        </div></section>
        <section class="surface rounded-2xl p-6"><h2 class="text-xl font-semibold">Availability and escalation</h2><div class="mt-5 grid gap-4 sm:grid-cols-2">
        @foreach(['heartbeat_timeout_seconds' => ['Heartbeat timeout (seconds)',90,60,600], 'healthy_heartbeats_to_resume' => ['Healthy heartbeats to resume',2,2,10], 'breaker_consecutive_threshold' => ['Consecutive faults to pause',3,1,20], 'breaker_rolling_threshold' => ['Faults in rolling window',5,1,100], 'breaker_window_minutes' => ['Fault window (minutes)',15,1,120], 'breaker_cooldown_minutes' => ['Cooldown (minutes)',10,1,1440], 'dispute_escalation_hours' => ['Escalate disputes after (hours)',24,1,168]] as $field => [$label,$default,$min,$max])
            <label class="field-label">{{ $label }}<input class="field" type="number" name="{{ $field }}" value="{{ old($field, $merchant->settings?->$field ?? $default) }}" min="{{ $min }}" max="{{ $max }}" required></label>
        @endforeach
        <label class="field-label">Checkout language<select class="field" name="default_locale">@foreach(['en'=>'English','hi'=>'हिन्दी','mr'=>'मराठी'] as $locale=>$label)<option value="{{ $locale }}" @selected(old('default_locale', $merchant->default_locale ?? 'en') === $locale)>{{ $label }}</option>@endforeach</select></label>
        </div></section>
        <div class="flex items-center gap-4"><button class="btn-primary">{{ $merchant->exists ? 'Save changes' : 'Create merchant' }} <span aria-hidden="true">→</span></button><a class="btn-secondary" href="{{ route('admin.merchants.index') }}">Cancel</a></div>
    </div>
    <aside class="surface-muted h-fit rounded-2xl p-6"><span class="status-pill bg-panel text-accent">Getting started</span><h2 class="mt-5 text-lg font-semibold">Ready in a few steps</h2><ol class="mt-5 space-y-5 text-sm text-muted"><li><strong class="text-strong">1. Create the account</strong><p class="mt-1">The owner can request a code from the login page.</p></li><li><strong class="text-strong">2. Connect a capture device</strong><p class="mt-1">Pair and verify a device, then wait for healthy heartbeats.</p></li><li><strong class="text-strong">3. Connect the store</strong><p class="mt-1">Create scoped keys and configure the webhook in Developer.</p></li></ol><p class="mt-6 border-t border-line pt-5 text-xs leading-5 text-muted">Creating an account does not open payments. A healthy verified device and an available gateway are required.</p></aside>
</form>
@endsection
