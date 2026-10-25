<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f5f7fb">
    <title>{{ $merchant->name }} · Pay with HX Pay</title>
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">try { document.documentElement.dataset.theme = localStorage.getItem('hx-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (_) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="pay-page antialiased">
    <a href="#main-content" class="skip-link">Skip to payment</a>
    <header class="pay-topbar"><a class="brand" href="{{ url('/') }}"><span class="app-brand-mark">HX</span><span>HX Pay<small>Simple, direct payments</small></span></a><div class="flex items-center gap-3"><span class="pay-top-caption">UPI checkout</span><button type="button" class="icon-button" data-theme-toggle aria-label="Switch colour theme" title="Switch colour theme">◐</button></div></header>
    <main id="main-content" class="pay-main">
        @if(session('status'))<div class="notice mb-5" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice notice-error mb-5" role="alert">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
    <footer class="pay-footer"><span class="pay-footer-mark">HX</span><span>Checkout by <strong>HX Pay</strong></span><span class="pay-footer-separator">·</span><span>Powered by UPI</span></footer>
</body>
</html>
