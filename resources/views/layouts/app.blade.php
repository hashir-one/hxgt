<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f5f7fb">
    <title>{{ $title ?? config('app.name') }}</title>
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">try { document.documentElement.dataset.theme = localStorage.getItem('hx-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (_) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen antialiased">
<a href="#main-content" class="skip-link">Skip to content</a>
@php($console = auth()->check() && !request()->is('checkout/*'))
<div class="app-shell {{ $console ? 'console-shell' : '' }}">
    @if($console)
    <aside class="app-sidebar" id="app-navigation">
        <a class="brand" href="{{ auth()->user()->isAdmin() ? route('admin.dashboard') : route('panel.dashboard') }}"><span class="app-brand-mark">HX</span><span>HX Pay<small>Payment operations</small></span></a>
        <p class="nav-caption">{{ auth()->user()->isAdmin() ? 'Platform workspace' : 'Merchant workspace' }}</p>
        <nav aria-label="Main navigation" class="side-nav">
            @php($links = auth()->user()->isAdmin() ? [['admin.dashboard', 'Overview', '◫'], ['admin.merchants.index', 'Merchants', '▦'], ['admin.operations', 'Operations', '◷'], ['admin.preview', 'Preview & test', '▷']] : [['panel.dashboard','Overview','◫'], ['panel.payments','Payments','↗'], ['panel.devices','Devices','▣'], ['panel.developer','Developer','⌘'], ['panel.preview','Preview & test','▷'], ['panel.disputes','Disputes','◷'], ['panel.settings','Settings','⚙'], ['panel.reports','Reports','▤'], ['panel.security','Security','◇']])
            @foreach($links as [$route, $label, $icon])
                @continue($route === 'panel.security' && auth()->user()->role !== 'owner')
                @php($active = request()->routeIs($route === 'admin.merchants.index' ? 'admin.merchants.*' : $route))
                <a href="{{ route($route) }}" class="nav-link {{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif><span aria-hidden="true">{{ $icon }}</span>{{ $label }}</a>
            @endforeach
            @if(auth()->user()->isAdmin() && app()->environment('local', 'testing'))
                <a href="{{ route('admin.test-lab') }}" class="nav-link {{ request()->routeIs('admin.test-lab*') ? 'active' : '' }}"><span aria-hidden="true">◇</span>Payment lab <small>Local</small></a>
            @endif
        </nav>
        <div class="sidebar-footer"><span class="status-pill">UPI payments</span><p>Direct to the merchant's bank account.</p></div>
    </aside>
    @endif
    <div class="min-w-0">
        <header class="app-header">
            <div class="flex min-w-0 items-center gap-3">
                @if($console)<button type="button" class="icon-button mobile-menu" data-menu-toggle aria-controls="app-navigation" aria-expanded="false" aria-label="Toggle navigation">☰</button><span class="text-sm text-muted">{{ auth()->user()->isAdmin() ? 'Platform' : auth()->user()->merchant?->name }} <span class="mx-2 opacity-40">/</span> <span class="text-strong">Workspace</span></span>
                @else<a class="brand" href="{{ url('/') }}"><span class="app-brand-mark">HX</span><span>HX Pay</span></a>@endif
            </div>
            <div class="flex items-center gap-3"><button type="button" class="icon-button" data-theme-toggle aria-label="Switch colour theme" title="Switch colour theme">◐</button>
                @if($console)<span class="user-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span><div class="hidden sm:block"><p class="text-xs font-semibold">{{ auth()->user()->name }}</p><p class="text-xs text-muted">{{ ucfirst(auth()->user()->role) }}</p></div><form method="post" action="{{ route('logout') }}">@csrf<button class="btn-secondary text-xs">Log out</button></form>@else<span class="hidden text-xs text-muted sm:block">UPI payment gateway</span>@endif
            </div>
        </header>
        <main id="main-content" class="mx-auto max-w-[1440px] px-4 py-7 sm:px-7 lg:px-9 lg:py-9">
            @if(session('status'))<div class="notice mb-6" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice notice-error mb-6" role="alert"><p class="font-semibold">Please check the following</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </main>
        <footer class="px-7 pb-6 text-xs text-muted">HX Pay <span class="mx-2">·</span> {{ now()->format('Y') }} <span class="mx-2">·</span> All times {{ config('app.timezone') }}</footer>
    </div>
</div>
</body>
</html>
