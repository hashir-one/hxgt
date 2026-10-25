@extends('layouts.app')
@section('content')
<section class="surface mb-8 rounded-2xl p-5 sm:p-6" aria-labelledby="live-test-title">
    <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="eyebrow">Actual UPI payment</p><h2 id="live-test-title" class="mt-2 text-2xl font-semibold">Test a real payment from ₹1</h2><p class="mt-3 max-w-3xl text-sm leading-6 text-muted">Generate an actual UPI QR from ₹1 and pay using your UPI app. Direct transfers can be tested now; tracked checkout needs the verified capture integration for automatic confirmation.</p></div><span class="status-pill bg-field text-warning">Real money</span></div>
    @if(!$merchant->exists)
        <p class="mt-5 text-sm font-semibold">Choose the merchant receiving the payment</p>
        <div class="mt-3 flex flex-wrap gap-3">@forelse($previewMerchants as $previewMerchant)<a class="btn-secondary" href="{{ route('admin.merchants.preview', $previewMerchant) }}">{{ $previewMerchant->name }} →</a>@empty<a class="btn-primary" href="{{ route('admin.merchants.create') }}">Add a merchant first</a>@endforelse</div>
    @else
        <p class="mt-4 text-sm">Recipient: <strong>{{ $merchant->name }}</strong>. A direct-transfer QR sends money to this merchant without creating a gateway order. Tracked checkout creates a real order and can trigger configured webhooks. Test transfers are not automatically refunded.</p>
        @if($liveIssues)
            <div class="notice notice-error mt-5"><p class="font-semibold">Automatic confirmation is not ready</p><ul class="mt-2 list-disc space-y-2 pl-5">@foreach($liveIssues as $issue)<li>{{ $issue }}</li>@endforeach</ul><p class="mt-3">A QR scan or a browser success message cannot confirm a real bank transfer.</p></div>
        @else
            <div class="notice mt-5">Capture device available. The gateway checks readiness again when you open checkout.</div>
        @endif
        @if(in_array(auth()->user()->role, ['admin', 'owner'], true) && $merchant->status === 'active')
            <form method="post" action="{{ auth()->user()->isAdmin() ? route('admin.merchants.preview.transfer', $merchant) : route('panel.preview.transfer') }}" class="mt-5 flex flex-wrap items-end gap-4">
                @csrf
                <input type="hidden" name="request_id" value="{{ $liveRequestId }}">
                <label class="field-label" for="live-test-amount">Amount (₹1–₹10)<input id="live-test-amount" name="live_amount" type="number" class="field" min="1" max="10" step="0.01" inputmode="decimal" value="{{ old('live_amount', '1.00') }}" required aria-describedby="live-amount-help"></label>
                <button class="btn-primary" type="submit">Show actual payment QR →</button>
                <button class="btn-secondary" type="submit" formaction="{{ auth()->user()->isAdmin() ? route('admin.merchants.preview.live', $merchant) : route('panel.preview.live') }}">Open tracked checkout →</button>
            </form>
            <p id="live-amount-help" class="mt-3 text-xs leading-6 text-muted">Direct-transfer QR: exactly the amount entered; check receipt in the bank account. Tracked checkout: adds matching paise (for example ₹1.01 for a ₹1 base amount) and confirms only after verified bank evidence. Pay exactly what its checkout shows.</p>
        @else
            <p class="mt-5 text-sm text-muted">An active merchant’s owner or a platform admin can start a real payment test.</p>
        @endif
    @endif
</section>
<div id="merchant-preview" data-expiry-minutes="{{ $expiryMinutes }}" data-email-minimum="{{ $emailMinimum }}">
    <div class="page-heading"><div><p class="eyebrow">Explore your payment experience</p><h1>Preview &amp; test</h1><p>See what customers see. Try a payment, follow its confirmation, and start again.</p></div><span class="status-pill bg-field text-accent">Demo mode · no money moves</span></div>
    <div class="notice mb-6"><strong>This is an interactive simulation.</strong> It creates no real orders, bank alerts, refunds, or webhooks. QR and app buttons are demonstrations. A successful demo does not verify your live integration.</div>
    <noscript><div class="notice notice-error mb-6">Enable JavaScript to use the interactive preview. No payment can be made from this page.</div></noscript>
    <div class="preview-workspace">
        <section class="surface rounded-2xl p-5 sm:p-6" aria-labelledby="preview-controls-title">
            <p class="eyebrow">01 · Set up a test</p><h2 id="preview-controls-title" class="mt-2 text-lg font-semibold">Your test payment</h2>
            <p class="mt-2 text-sm leading-6 text-muted">Your store name and uploaded logo appear in the preview. Every session starts fresh.</p>
            <form data-preview-form class="mt-5 space-y-4" hidden>
                <label class="field-label" for="preview-amount">Order amount (INR)<input id="preview-amount" name="amount" class="field" inputmode="decimal" value="500.00" required maxlength="12" aria-describedby="preview-amount-help"><span id="preview-amount-help" class="field-help">₹0.01–₹1,000,000.00. A fixed ₹0.37 demo suffix illustrates the exact payable amount.</span></label>
                <button class="btn-primary w-full" type="submit">Start new test</button>
            </form>
            <p class="mt-5 eyebrow">02 · Try a scenario</p>
            <div data-preview-controls hidden class="mt-3 grid gap-2">
                <button type="button" class="btn-primary justify-start" data-preview-action="automatic">✓ Successful payment</button>
                <button type="button" class="btn-secondary justify-start" data-preview-action="sms">1. Receive SMS alert</button>
                <button type="button" class="btn-secondary justify-start" data-preview-action="notification">2. Receive bank notification</button>
                <button type="button" class="btn-secondary justify-start" data-preview-action="email">Confirm with bank email</button>
                <p class="px-1 text-xs leading-5 text-muted">Email confirmation starts at ₹{{ number_format($emailMinimum / 100, 2) }}. One alert alone leaves a payment awaiting verification.</p>
                <div class="grid grid-cols-2 gap-2"><button type="button" class="btn-secondary" data-preview-action="review">Needs review</button><button type="button" class="btn-secondary" data-preview-action="expire">Expire order</button></div>
                <button type="button" class="btn-secondary" data-preview-action="pause">Pause gateway</button>
            </div>
            <p data-preview-message role="status" aria-live="polite" class="mt-4 text-sm leading-6 text-accent">Start with a successful payment, or add the two bank alerts one at a time.</p>
            <div class="mt-5 border-t border-line pt-5"><p class="font-semibold text-sm">Ready to integrate?</p><p class="mt-2 text-xs leading-5 text-muted">Live checkout also needs a healthy capture device, configured payment rules, and server verification of signed events.</p><a class="mt-3 inline-block text-sm text-accent" href="{{ auth()->user()->isAdmin() ? route('admin.merchants.index') : route('panel.developer') }}">{{ auth()->user()->isAdmin() ? 'Manage merchants' : 'Open developer setup' }} →</a></div>
        </section>
        <section class="min-w-0" aria-labelledby="preview-customer-title">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><div><p class="eyebrow">Customer view</p><h2 id="preview-customer-title" class="mt-1 text-sm text-muted">Hosted checkout · {{ $merchant->name }}</h2></div><div data-preview-controls hidden class="flex gap-2" role="group" aria-label="Preview width"><button type="button" class="btn-secondary text-xs" data-preview-width="mobile" aria-pressed="true">Mobile</button><button type="button" class="btn-secondary text-xs" data-preview-width="desktop" aria-pressed="false">Desktop</button></div></div>
            <div class="preview-canvas rounded-3xl border border-line bg-field p-3 sm:p-6">
                <div data-preview-device class="preview-device preview-mobile">
                    <div class="preview-merchant-banner">@if($logo)<img class="mx-auto mb-3 h-12 w-12 rounded-xl object-contain" src="{{ $logo }}" alt="{{ $merchant->name }} logo">@else<span class="mx-auto mb-3 grid size-12 place-items-center rounded-xl bg-panel text-lg font-bold text-accent">{{ mb_strtoupper(mb_substr($merchant->name, 0, 1)) }}</span>@endif<p class="eyebrow">Pay with UPI · Demo</p><h3 class="mt-2 text-xl font-semibold break-words">{{ $merchant->name }}</h3><p class="mt-2 text-xs text-muted">Order <span data-preview-order>DEMO-0001</span></p></div>
                    <section class="preview-payment-card">
                        <div class="preview-amount-banner"><p data-preview-amount-label class="text-sm text-muted">Pay exactly · demo amount</p><p data-preview-payable class="mt-2 break-words text-4xl font-semibold tracking-tight">₹500.37</p><p class="mt-3 text-xs text-muted">INR · Simulated payment only</p></div>
                        <div class="p-5 sm:p-6">
                            <div data-preview-state class="notice text-center" role="status" aria-live="polite">Waiting for payment</div>
                            <div data-preview-payment><div class="pay-method-row"><span class="pay-method-selected"><span class="pay-radio" aria-hidden="true"></span>UPI</span><span class="pay-method-note">QR &amp; apps · Demo</span></div>
                                <div class="preview-code mx-auto mt-5" role="img" aria-label="Non-scannable QR placeholder for the checkout demo"><span class="preview-code-corner"></span><span class="preview-code-corner"></span><span class="preview-code-corner"></span><strong>DEMO<span>Not a payment QR</span></strong></div>
                                <p class="mt-4 text-center text-xs text-muted">Demo QR · Expires in <span data-preview-countdown class="font-mono">{{ $expiryMinutes }}:00</span></p>
                                <p class="mt-3 text-center text-xs leading-5 text-muted">In live checkout, customers scan a QR or open a UPI app and pay the exact amount.</p>
                                <div data-preview-controls hidden class="mt-5 grid grid-cols-2 gap-2"><button type="button" class="btn-primary text-xs" data-preview-action="app">Try UPI app</button><button type="button" class="btn-secondary text-xs" data-preview-action="copy">Try copy UPI ID</button></div>
                                <p class="mt-3 text-center font-mono text-xs text-muted">preview@demo · example only</p>
                            </div>
                            <div data-preview-receipt hidden class="mt-5 rounded-2xl bg-field p-4 text-sm"><p class="font-semibold text-positive">Test payment received</p><dl class="mt-4 space-y-3"><div class="flex justify-between gap-3"><dt class="text-muted">Reference</dt><dd class="font-mono">DEMO-REFERENCE</dd></div><div class="flex justify-between gap-3"><dt class="text-muted">Confirmation</dt><dd data-preview-method></dd></div><div class="flex justify-between gap-3"><dt class="text-muted">Receipt</dt><dd>Demo only</dd></div></dl><button type="button" data-preview-action="return" class="btn-primary mt-5 w-full">Preview return to store →</button></div>
                            <div data-preview-return hidden class="mt-5 rounded-2xl bg-field p-5 text-center"><p class="text-lg font-semibold text-positive">Thank you for your test order</p><p class="mt-3 text-sm leading-6 text-muted">This represents your store’s confirmation page. For real orders, your server must verify payment before fulfilling the order.</p></div>
                            <div data-preview-help class="mt-5 border-t border-line pt-4"><details><summary class="cursor-pointer text-sm font-semibold text-accent">Paid but not confirmed?</summary><p class="mt-3 text-xs leading-6 text-muted">In live checkout, customers can submit their 12-digit bank reference after one minute, then contact your support team. Use “Needs review” to explore that outcome here.</p></details><p class="mt-4 text-xs text-muted">{{ $merchant->support_phone ? 'Merchant support: '.$merchant->support_phone : 'Your support contact appears here once configured.' }}</p></div>
                        </div>
                    </section>
                    <p class="mt-4 text-center text-xs text-muted">HX Pay · Interactive checkout preview</p>
                </div>
            </div>
        </section>
    </div>
    <section class="surface mt-6 rounded-2xl p-5 sm:p-6" aria-labelledby="preview-journey-title"><div class="flex flex-wrap items-center justify-between gap-3"><div><p class="eyebrow">03 · Follow the journey</p><h2 id="preview-journey-title" class="mt-2 text-lg font-semibold">What happens behind the checkout</h2></div><span class="status-pill bg-field">Illustration only</span></div><ol class="mt-5 grid gap-3 sm:grid-cols-3"><li class="rounded-xl bg-field p-4 text-sm"><strong>1. Order opened</strong><p class="mt-2 text-xs text-muted">A demo amount and checkout are ready.</p></li><li class="rounded-xl bg-field p-4 text-sm"><strong>2. Payment verified</strong><p data-preview-verify class="mt-2 text-xs text-muted">Waiting for simulated bank evidence.</p></li><li class="rounded-xl bg-field p-4 text-sm"><strong>3. Store notified</strong><p data-preview-delivery class="mt-2 text-xs text-muted">No real webhook will be sent.</p></li></ol><ol data-preview-timeline aria-label="Simulation activity" class="mt-5 space-y-2 text-sm text-muted"></ol><p class="mt-5 text-xs leading-6 text-muted">Demo outcomes are simplified. Live confirmation also checks device trust, amount, reference, timing, limits and gateway health. Test your actual API and webhook integration separately before accepting payments.</p></section>
</div>
@endsection
