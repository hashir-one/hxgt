@extends('layouts.checkout')
@section('content')
<div class="pay-breadcrumb"><a href="{{ auth()->user()->isAdmin() ? route('admin.merchants.preview', $merchant) : route('panel.preview') }}">← Preview &amp; test</a><span aria-hidden="true">/</span><strong>Direct transfer</strong></div>
<div class="pay-card">
    @include('checkout.partials.summary', ['isTransfer' => true])
    <section class="pay-content" aria-labelledby="payment-title">
        <div class="pay-content-heading"><div><p class="eyebrow">Actual UPI payment</p><h2 id="payment-title">Scan and pay ₹{{ number_format($amountMinor / 100, 2) }}</h2><p>Pay directly from your favourite UPI app.</p></div><span class="pay-method-symbol" aria-hidden="true">↗</span></div>
        <div class="pay-method-row"><span class="pay-method-selected"><span class="pay-radio" aria-hidden="true"></span>UPI</span><span class="pay-recommended">Direct transfer</span><span class="pay-method-note">QR &amp; apps</span></div>
        <div class="pay-scan-area">
            <div class="pay-qr-frame"><div class="pay-qr" role="img" aria-label="Actual UPI payment QR for {{ $merchant->name }}">{!! $qr !!}</div><span class="pay-qr-caption">SCAN &amp; PAY</span></div>
            <div class="pay-scan-copy"><h3>Scan with any UPI app</h3><p>Check the recipient in your app, then approve the exact amount.</p><div class="pay-app-labels" aria-label="Compatible with UPI apps"><span>GPay</span><span>PhonePe</span><span>Paytm</span><span>BHIM</span></div><span class="pay-direct-amount">₹{{ number_format($amountMinor / 100, 2) }} · INR</span></div>
        </div>
        <div class="pay-divider"><span>paying on your phone?</span></div>
        <a class="btn-primary pay-full-button" href="{{ $upiUri }}">Open UPI app <span aria-hidden="true">↗</span></a>
        <p class="pay-vpa">{{ $merchant->upi_vpa }}</p>
        <div class="pay-transfer-notice"><span aria-hidden="true">i</span><div><strong>Confirm receipt in your bank account</strong><p>Automatic confirmation is not available for this direct-transfer QR.</p><p>This sends real money. It does not create a gateway order or automatically refund the transfer.</p></div></div>
        <details class="pay-transfer-help"><summary>About this test <span aria-hidden="true">⌄</span></summary><p>Generating or scanning this QR does not prove payment. No webhook is sent and no payment is marked successful. For automatic confirmation, connect verified bank capture and use “Open tracked checkout”.</p><p class="break-all">Test reference: {{ $reference }}</p></details>
    </section>
</div>
@endsection
