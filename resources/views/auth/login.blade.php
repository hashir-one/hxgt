@extends('layouts.app')

@section('content')
    <div class="mx-auto grid max-w-6xl gap-10 py-6 lg:grid-cols-[1.05fr_.95fr] lg:items-center lg:py-14">
        <section class="max-w-xl">
            <div class="mb-7 inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"><span class="size-1.5 rounded-full bg-blue-600"></span> Gateway operations · fail closed</div>
            <p class="eyebrow">Merchant control centre</p>
            <h1 class="mt-5 text-4xl font-semibold leading-[1.05] tracking-[-.04em] text-strong sm:text-6xl">Run payment confirmation with confidence.</h1>
            <p class="mt-6 max-w-lg text-base leading-8 text-muted sm:text-lg">One place to monitor devices, resolve payment exceptions and verify every order from the bank's own signals.</p>
            <div class="mt-9 grid gap-3 sm:grid-cols-3">
                @foreach([['01','Two sources','SMS + notification'],['02','Exact amount','Unique rupee amount'],['03','Server truth','Signed event trail']] as [$number,$title,$text])
                    <div class="surface-muted rounded-2xl p-4"><p class="text-xs font-bold text-blue-700">{{ $number }}</p><p class="mt-4 text-sm font-semibold text-strong">{{ $title }}</p><p class="mt-1 text-xs leading-5 text-muted">{{ $text }}</p></div>
                @endforeach
            </div>
        </section>
        <section class="surface mx-auto w-full max-w-md rounded-[28px] p-6 sm:p-8">
            <div class="flex items-start justify-between gap-4"><div><p class="eyebrow">Secure sign in</p><h2 class="mt-2 text-2xl font-semibold text-strong">Welcome back</h2></div><span class="grid size-11 place-items-center rounded-2xl bg-blue-50 text-blue-700"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3 5 6v5c0 4.4 2.8 8.2 7 10 4.2-1.8 7-5.6 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/></svg></span></div>
            <p class="mt-3 text-sm leading-6 text-muted">Use your owner or platform-admin email. We will send a single-use code that expires in five minutes.</p>
            
            
            <form method="post" action="{{ route('login.request') }}" class="mt-6 space-y-4">@csrf
                <label class="block text-sm font-medium text-muted">Work email<input name="email" type="email" required autocomplete="email" value="{{ old('email') }}" placeholder="you@company.com" class="mt-2 w-full rounded-2xl border border-line bg-panel px-4 py-3.5 text-strong outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"></label>
                <button class="flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 px-4 py-3.5 font-bold text-strong shadow-sm hover:bg-blue-700">Send verification code <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            </form>
            <div class="my-6 flex items-center gap-3 text-[11px] font-semibold uppercase tracking-[.16em] text-muted"><span class="h-px flex-1 bg-field"></span> Already have a code? <span class="h-px flex-1 bg-field"></span></div>
            <form method="post" action="{{ route('login.verify') }}" class="space-y-4">@csrf
                <label class="block text-sm font-medium text-muted">Verification code<input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="6-digit code" class="mt-2 w-full rounded-2xl border border-line bg-panel px-4 py-3.5 text-center text-xl font-semibold tracking-[.35em] text-strong outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"></label>
                <button class="w-full rounded-2xl border border-line bg-panel px-4 py-3.5 font-bold text-slate-800 hover:border-blue-200 hover:bg-blue-50">Open operations console</button>
            </form>
            <p class="mt-6 text-center text-xs leading-5 text-muted">Access is protected by rate limits, one-time codes and session rotation.</p>
        </section>
    </div>
@endsection
