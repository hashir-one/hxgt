@extends('layouts.app')

@section('content')
    <div class="py-6 lg:py-14">
        <div class="grid items-center gap-12 lg:grid-cols-[1.08fr_.92fr]">
            <section>
                <div class="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"><span class="size-1.5 rounded-full bg-blue-600"></span> UPI payment infrastructure for serious teams</div>
                <p class="eyebrow mt-8">HX UPI Gateway · PRD 2.0</p>
                <h1 class="mt-5 max-w-3xl text-5xl font-semibold leading-[.98] tracking-[-.055em] text-strong sm:text-7xl">Payment confirmation built on bank evidence.</h1>
                <p class="mt-7 max-w-2xl text-lg leading-8 text-muted">Hosted checkout, exact rupee allocation, signed device ingestion, two-source matching and merchant-side verification — designed to stop safely when the signal is unclear.</p>
                <div class="mt-9 flex flex-wrap gap-3"><a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-2xl bg-blue-600 px-5 py-3.5 font-bold text-strong shadow-sm hover:bg-blue-700">Open merchant console <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a><a href="{{ url('/up') }}" class="rounded-2xl border border-line bg-panel px-5 py-3.5 font-bold text-slate-800 hover:border-blue-200 hover:bg-blue-50">System health</a></div>
                <div class="mt-12 grid max-w-2xl grid-cols-3 gap-5 border-t border-line pt-6"><div><p class="text-2xl font-semibold text-strong">2-of-2</p><p class="mt-1 text-xs text-muted">bank evidence rule</p></div><div><p class="text-2xl font-semibold text-strong">₹0.01</p><p class="mt-1 text-xs text-muted">minimum unique suffix</p></div><div><p class="text-2xl font-semibold text-strong">0</p><p class="mt-1 text-xs text-muted">browser-trusted payments</p></div></div>
            </section>
            <section class="surface relative overflow-hidden rounded-[32px] p-6 sm:p-8">
                <div class="relative"><div class="flex items-center justify-between"><div><p class="eyebrow">Confirmation model</p><h2 class="mt-2 text-2xl font-semibold text-strong">Fail closed by default</h2></div><span class="status-pill bg-emerald-50 text-emerald-700"><span class="size-1.5 rounded-full bg-emerald-600"></span> Protected</span></div>
                <div class="mt-7 space-y-3">@foreach([['01','Exact amount','Each order reserves a unique rupee amount.'],['02','Two independent alerts','SMS and Kotak notification must agree.'],['03','Server-side truth','Browser redirects never change payment state.'],['04','Audited recovery','Claims and manual actions leave a trail.']] as [$n,$featureTitle,$featureText])<div class="flex gap-4 rounded-2xl border border-line bg-field p-4"><span class="text-sm font-bold text-blue-700">{{ $n }}</span><div><h2 class="text-sm font-semibold text-strong">{{ $featureTitle }}</h2><p class="mt-1 text-sm leading-6 text-muted">{{ $featureText }}</p></div></div>@endforeach</div></div>
            </section>
        </div>
        <section class="mt-16 grid gap-4 border-t border-line pt-8 md:grid-cols-3"><div><p class="text-sm font-semibold text-strong">Hosted checkout</p><p class="mt-2 text-sm leading-6 text-muted">Clear exact-amount instructions, QR, UPI deep links and recovery paths.</p></div><div><p class="text-sm font-semibold text-strong">Operations visibility</p><p class="mt-2 text-sm leading-6 text-muted">Device health, breaker state, disputes and delivery history in one console.</p></div><div><p class="text-sm font-semibold text-strong">Merchant verification</p><p class="mt-2 text-sm leading-6 text-muted">Thin signed webhooks plus a separate pull API for two-channel fulfilment.</p></div></section>
    </div>
@endsection
