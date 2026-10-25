@extends('layouts.checkout')
@section('content')
<div class="pay-breadcrumb"><span>Checkout</span><span aria-hidden="true">/</span><strong>{{ __('Payment') }}</strong><nav aria-label="Checkout language">@foreach(['en'=>'English','hi'=>'हिन्दी','mr'=>'मराठी'] as $code=>$label)<a href="{{ request()->fullUrlWithQuery(['lang'=>$code]) }}" lang="{{ $code }}" @if(app()->getLocale()===$code) aria-current="true" @endif>{{ $label }}</a>@endforeach</nav></div>
@if(($order->metadata['purpose'] ?? null) === 'live_test')
<div class="pay-test-notice"><strong>Real payment test.</strong> This QR transfers money to {{ $merchant->name }}. Test payments are not automatically refunded.</div>
@endif
<div id="checkout" class="pay-card" data-events-url="{{ route('checkout.events', ['token' => request()->route('token')]) }}" data-status-url="{{ route('checkout.status', ['token' => request()->route('token')]) }}" data-return-url="{{ $order->return_url }}" data-expires-at="{{ $order->expires_at->toIso8601String() }}" data-created-at="{{ $order->created_at->toIso8601String() }}" data-vpa="{{ $merchant->upi_vpa }}">
    @include('checkout.partials.summary', ['amountMinor' => $order->payable_amount_minor, 'reference' => $order->merchant_order_id, 'isTransfer' => false, 'merchantLogo' => $merchant->logo_path ? route('checkout.logo', ['token' => request()->route('token')]) : null])
    <section class="pay-content" aria-labelledby="payment-title">
        <div class="pay-content-heading"><div><p class="eyebrow">One step to go</p><h2 id="payment-title">{{ __('Pay with UPI') }}</h2><p>{{ __('Scan with any UPI app') }}</p></div><span class="pay-method-symbol" aria-hidden="true">↗</span></div>
        <div class="pay-method-row"><span class="pay-method-selected"><span class="pay-radio" aria-hidden="true"></span>UPI</span><span class="pay-recommended">Recommended</span><span class="pay-method-note">QR &amp; apps</span></div>
        <div id="state" class="pay-state" role="status" aria-live="polite">{{ $order->status === 'paid' ? __('Payment confirmed') : ($paused ? __('Payments temporarily unavailable') : ($payable ? __('Waiting for payment') : __('This order is no longer accepting payments'))) }}</div>
        <div id="payment-actions" @if(!$payable) hidden @endif>
            <div class="pay-scan-area">
                @if($qr)<div class="pay-qr-frame"><div class="pay-qr">{!! $qr !!}</div><span class="pay-qr-caption">SCAN &amp; PAY</span></div>@endif
                <div class="pay-scan-copy"><h3>{{ __('Scan to pay') }}</h3><p>Open your preferred UPI app and scan this QR code.</p><div class="pay-app-labels" aria-label="Compatible with UPI apps"><span>GPay</span><span>PhonePe</span><span>Paytm</span><span>BHIM</span></div><div class="pay-timer">{{ __('Expires in') }} <strong id="countdown">—</strong></div></div>
            </div>
            <div class="pay-divider"><span>or pay on this phone</span></div>
            <div id="android-upi-apps" hidden><div class="pay-app-grid">@foreach($upiApps as $name => $uri)<a href="{{ $uri }}"><span class="pay-app-avatar">{{ mb_substr($name, 0, 1) }}</span><span>{{ $name }}</span><span aria-hidden="true">↗</span></a>@endforeach</div></div>
            <div class="pay-primary-actions"><a class="btn-primary" href="{{ $upiUri }}">{{ __('Open UPI app') }} <span aria-hidden="true">↗</span></a><button type="button" id="copy-vpa" class="btn-secondary">{{ __('Copy UPI ID') }}</button></div>
            <p class="pay-vpa">{{ $merchant->upi_vpa }}</p>
        </div>
        <div id="receipt" @if($order->status !== 'paid') hidden @endif class="pay-receipt"><div class="pay-receipt-icon" aria-hidden="true">✓</div><h3>{{ __('Payment received') }}</h3><p>Your payment has been confirmed.</p><dl><div><dt>{{ __('Order') }}</dt><dd>{{ $order->merchant_order_id }}</dd></div><div><dt>{{ __('Reference') }}</dt><dd id="receipt-reference">{{ $order->status === 'paid' ? $order->payment?->reference_number_normalized : '' }}</dd></div><div><dt>{{ __('Confirmed at') }}</dt><dd id="receipt-time">{{ $order->paid_at?->toIso8601String() }}</dd></div></dl></div>
        <div id="recovery" @if($order->status === 'paid') hidden @endif class="pay-recovery">
            <details id="claim-details" @if($order->created_at->gt(now()->subSeconds(60))) hidden @endif><summary>{{ __('Paid but not confirmed?') }} <span aria-hidden="true">⌄</span></summary><form id="claim" class="mt-4" method="post" action="{{ route('checkout.claim', ['token' => request()->route('token')]) }}">@csrf<label class="field-label">{{ __('12-digit UPI reference') }}<input class="field" name="reference_number" inputmode="numeric" maxlength="12" pattern="[0-9]{12}" required placeholder="123456789012"></label><div class="cf-turnstile mt-3" data-sitekey="{{ config('gateway.claims.captcha_site_key') }}" data-action="payment-claim"></div><button class="btn-secondary mt-3">{{ __('Check bank reference') }}</button><p id="claim-message" class="mt-3 text-sm text-muted" role="status"></p></form></details>
            @if($merchant->support_phone)<div class="pay-support"><p>{{ __('Need help? Contact the merchant and quote order :order.', ['order' => $order->merchant_order_id]) }}</p><div><a href="tel:{{ preg_replace('/[^+0-9]/', '', $merchant->support_phone) }}">{{ $merchant->support_phone }}</a><a href="https://wa.me/{{ preg_replace('/\D/', '', $merchant->support_phone) }}?text={{ rawurlencode('I need help with payment for order '.$order->merchant_order_id.'.') }}" target="_blank" rel="noopener noreferrer">WhatsApp ↗</a></div></div>@endif
        </div>
        <p class="pay-bottom-note">{{ __('Payment status is confirmed by the server from captured bank alerts.') }}</p>
    </section>
</div>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const checkout = document.getElementById('checkout');
    const state = document.getElementById('state');
    const actions = document.getElementById('payment-actions');
    document.getElementById('android-upi-apps').hidden = !/Android/i.test(navigator.userAgent);
    let terminal = false;
    let timer;
    let inFlight = false;
    let redirectScheduled = false;
    let stream;
    let pollDelay = 3000;
    const labels = {waiting:@js(__('Waiting for payment')), detected:@js(__('Payment detected. Verifying the second bank alert.')), confirmed:@js(__('Payment confirmed')), expired:@js(__('This order has expired. Contact the merchant to retry or resolve a payment.')), needs_review:@js(__('Your payment needs merchant review.')), gateway_paused:@js(__('Payments temporarily unavailable. Please wait before paying.'))};
    const poll = async () => {
        if (inFlight || terminal) return;
        clearTimeout(timer);
        inFlight = true;
        try {
            const response = await fetch(checkout.dataset.statusUrl, {headers:{Accept:'application/json'}, cache:'no-store'});
            if (!response.ok) throw new Error('Status unavailable');
            const data = await response.json();
            state.textContent = labels[data.state] || @js(__('Checking payment status'));
            if (['waiting','detected'].includes(data.state) && !actions.querySelector('svg')) { location.reload(); return; }
            actions.hidden = !['waiting','detected'].includes(data.state);
            terminal = data.state === 'confirmed';
            pollDelay = ['expired','needs_review'].includes(data.state) ? 15000 : 3000;
            if (terminal) stream?.close();
            if (data.state === 'confirmed') {
                document.getElementById('receipt').hidden = false;
                document.getElementById('recovery').hidden = true;
                document.getElementById('receipt-reference').textContent = data.reference_number || '—';
                document.getElementById('receipt-time').textContent = data.paid_at || '—';
                if (checkout.dataset.returnUrl && !redirectScheduled) {
                    redirectScheduled = true;
                    setTimeout(() => location.assign(checkout.dataset.returnUrl), 2500);
                }
            }
        } catch (_) {
            state.textContent = @js(__('Unable to refresh status. Reconnecting…'));
            actions.hidden = true;
        } finally {
            inFlight = false;
            if (!terminal) timer = setTimeout(poll, pollDelay);
        }
    };
    const tick = () => {
        const seconds = Math.max(0, Math.floor((Date.parse(checkout.dataset.expiresAt) - Date.now()) / 1000));
        document.getElementById('countdown').textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        if (!seconds) actions.hidden = true;
        if (Date.now() - Date.parse(checkout.dataset.createdAt) >= 60000) document.getElementById('claim-details').hidden = false;
    };
    tick(); setInterval(tick, 1000); poll();
    if (@js(config('gateway.checkout_stream_enabled')) && 'EventSource' in window) {
        stream = new EventSource(checkout.dataset.eventsUrl);
        stream.addEventListener('payment', () => { if (!terminal) poll(); else stream.close(); });
        window.addEventListener('pagehide', () => stream.close(), {once: true});
        stream.onerror = () => { stream.close(); if (!terminal) poll(); };
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    window.addEventListener('pageshow', poll);
    document.getElementById('copy-vpa').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        try { await navigator.clipboard.writeText(checkout.dataset.vpa); button.textContent = @js(__('UPI ID copied')); }
        catch (_) { state.textContent = @js(__('Copy this UPI ID:')) + ' ' + checkout.dataset.vpa; }
    });
    document.getElementById('claim').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button');
        const message = document.getElementById('claim-message');
        button.disabled = true;
        try {
            const response = await fetch(form.action, {method:'POST', headers:{Accept:'application/json'}, body:new FormData(form)});
            const data = await response.json();
            message.textContent = response.status === 429 ? @js(__('Too many attempts. Please contact the merchant.')) : (data.message || data.status || @js(__('Unable to check that reference.')));
            if (response.ok) { terminal = false; poll(); }
        } catch (_) { message.textContent = @js(__('Unable to connect. Please try again.')); }
        finally { button.disabled = false; window.turnstile?.reset(); }
    });
})();
</script>
@endsection
