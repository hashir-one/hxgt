@extends('layouts.app')

@section('content')
    @php
        $icon = fn (string $name) => match ($name) {
            'play' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m8 5 11 7-11 7V5Z" stroke-linejoin="round"/></svg>',
            'mail' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>',
            'message' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 5h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H9l-4 3v-3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z"/><path d="M7 10h10M7 13h6"/></svg>',
            'bell' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'info' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01" stroke-linecap="round"/></svg>',
            default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg>',
        };
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-5">
        <div>
            <p class="eyebrow">Admin developer workspace</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-strong sm:text-4xl">Payment simulation lab</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Run realistic confirmation paths against isolated test records. The production gateway and the sandbox setting are not changed by this page.</p>
        </div>
        <span class="status-pill border border-blue-200 bg-blue-50 text-blue-700"><span class="size-1.5 rounded-full bg-blue-600"></span> Isolated test data</span>
    </div>

    @if(session('status'))
        <div class="mt-6 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
            <span class="mt-0.5 size-5 shrink-0">{!! $icon('check') !!}</span>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if($errors->any())
        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <p class="font-semibold">The simulation was not completed.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-7 grid gap-6 xl:grid-cols-[.75fr_1.25fr]">
        <div class="space-y-6">
            <section class="surface rounded-[28px] p-6">
                <div class="flex items-start gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-blue-50 text-blue-700">{!! $icon('play') !!}</span>
                    <div>
                        <p class="eyebrow">Create fixture</p>
                        <h2 class="mt-2 text-xl font-semibold text-strong">New test payment</h2>
                        <p class="mt-2 text-sm leading-6 text-muted">Enter the amount in rupees. The lab adds a small unique suffix to create the exact amount a customer would pay.</p>
                    </div>
                </div>

                <form method="post" action="{{ route('admin.test-lab.create') }}" class="mt-6 space-y-4">
                    @csrf
                    <label class="block text-sm font-medium text-muted">
                        Base amount
                        <span class="relative mt-2 block">
                            <span class="pointer-events-none absolute inset-y-0 left-4 grid place-items-center font-semibold text-blue-700">₹</span>
                            <input name="amount" type="number" min="0.01" step="0.01" value="{{ old('amount', '500.00') }}" required inputmode="decimal" placeholder="500.00" class="w-full rounded-2xl border border-line bg-panel px-4 py-3 pl-9 text-strong outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100">
                        </span>
                        <span class="mt-2 block text-xs font-normal text-muted">Example: ₹500.00. The created payment may show as ₹500.37 because the gateway reserves a unique suffix.</span>
                    </label>
                    <button class="flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 px-4 py-3.5 font-bold text-strong shadow-sm hover:bg-blue-700">
                        <span class="size-5">{!! $icon('play') !!}</span>
                        Create isolated payment
                    </button>
                </form>

                <div class="mt-6 flex items-start gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 text-xs leading-5 text-blue-800">
                    <span class="mt-0.5 size-5 shrink-0">{!! $icon('info') !!}</span>
                    <p>This lab writes only to <code class="rounded bg-panel px-1 py-0.5 font-mono text-[11px]">sandbox_test_*</code> tables. It never creates a live order, payment, webhook, or bank signal.</p>
                </div>
            </section>

            <section id="how-to-use" class="surface rounded-[28px] p-6">
                <p class="eyebrow">How to use</p>
                <h2 class="mt-2 text-xl font-semibold text-strong">Test the payment lifecycle</h2>
                <ol class="mt-5 space-y-4">
                    <li class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-blue-600 text-xs font-bold text-strong">1</span><div><p class="text-sm font-semibold text-slate-800">Create a test payment</p><p class="mt-1 text-xs leading-5 text-muted">Use a rupee amount. The page shows the exact payable amount and a 12-digit reference.</p></div></li>
                    <li class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-blue-600 text-xs font-bold text-strong">2</span><div><p class="text-sm font-semibold text-slate-800">Choose a payment path</p><p class="mt-1 text-xs leading-5 text-muted">Use automatic payment for SMS plus notification, or bank email for the email confirmation rule.</p></div></li>
                    <li class="flex gap-3"><span class="grid size-7 shrink-0 place-items-center rounded-full bg-blue-600 text-xs font-bold text-strong">3</span><div><p class="text-sm font-semibold text-slate-800">Verify the result</p><p class="mt-1 text-xs leading-5 text-muted">One signal leaves the payment detected. Two independent signals or an eligible bank email mark it paid.</p></div></li>
                </ol>
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-2xl border border-line bg-field p-4"><p class="text-xs font-bold text-muted">Automatic path</p><p class="mt-1 text-xs leading-5 text-muted">SMS → notification → paid</p></div>
                    <div class="rounded-2xl border border-line bg-field p-4"><p class="text-xs font-bold text-muted">Bank email path</p><p class="mt-1 text-xs leading-5 text-muted">Available from {{ $emailMinimum }} → paid</p></div>
                </div>
            </section>
        </div>

        <section class="surface rounded-[28px] p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="eyebrow">Fixtures</p>
                    <h2 class="mt-2 text-xl font-semibold text-strong">Simulate payment events</h2>
                </div>
                <span class="rounded-full bg-field px-3 py-1 text-xs font-semibold text-muted">{{ $orders->count() }} recent</span>
            </div>

            <div class="mt-5 space-y-4">
                @forelse($orders as $order)
                    @php
                        $sources = $order->signals->pluck('source')->all();
                        $isPaid = $order->status === 'paid';
                        $emailEligible = $order->payable_amount_minor >= config('gateway.email.minimum_amount_minor');
                    @endphp
                    <article class="rounded-2xl border border-line bg-panel p-5 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-2xl {{ $isPaid ? 'bg-emerald-50 text-emerald-700' : 'bg-blue-50 text-blue-700' }}">{!! $icon($isPaid ? 'check' : 'play') !!}</span>
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-strong">{{ $order->merchant_order_id }}</p>
                                        <p class="mt-1 text-xs text-muted">Created {{ $order->created_at->diffForHumans() }} · expires {{ $order->expires_at->diffForHumans() }}</p>
                                    </div>
                                </div>
                                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                                    <span>Base <strong class="text-slate-800">₹{{ number_format($order->requested_amount_minor / 100, 2) }}</strong></span>
                                    <span>Pay exactly <strong class="text-slate-800">₹{{ number_format($order->payable_amount_minor / 100, 2) }}</strong></span>
                                    <span>Ref <code class="font-mono text-muted">{{ $order->reference_number_normalized }}</code></span>
                                </div>
                            </div>
                            <span class="status-pill {{ $isPaid ? 'bg-emerald-50 text-emerald-700' : ($order->status === 'detected' ? 'bg-amber-50 text-amber-700' : 'bg-field text-muted') }}">{{ str_replace('_', ' ', $order->status) }}</span>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            @foreach(['sms' => 'SMS', 'notification' => 'Notification', 'email' => 'Bank email'] as $source => $label)
                                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ in_array($source, $sources, true) ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-line bg-field text-muted' }}">
                                    <span class="size-4">{!! $icon($source === 'sms' ? 'message' : ($source === 'notification' ? 'bell' : 'mail')) !!}</span>
                                    {{ $label }} {{ in_array($source, $sources, true) ? 'received' : 'waiting' }}
                                </span>
                            @endforeach
                            @if($order->confirmation_method)
                                <span class="text-xs font-medium text-muted">Confirmed by {{ str_replace('_', ' ', $order->confirmation_method) }}</span>
                            @endif
                        </div>

                        @if(!$isPaid)
                            <div class="mt-5 grid gap-2 border-t border-line pt-4 sm:grid-cols-2">
                                <form method="post" action="{{ route('admin.test-lab.simulate', $order) }}">
                                    @csrf
                                    <input type="hidden" name="scenario" value="automatic">
                                    <button class="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-3 py-2.5 text-xs font-bold text-strong hover:bg-blue-700"><span class="size-4">{!! $icon('play') !!}</span> Simulate automatic payment</button>
                                </form>
                                <form method="post" action="{{ route('admin.test-lab.simulate', $order) }}">
                                    @csrf
                                    <input type="hidden" name="scenario" value="bank_email">
                                    <button @disabled(!$emailEligible) class="flex w-full items-center justify-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2.5 text-xs font-bold text-blue-700 enabled:hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-45"><span class="size-4">{!! $icon('mail') !!}</span> {{ $emailEligible ? 'Simulate bank email' : 'Bank email needs '.$emailMinimum }}</button>
                                </form>
                                <form method="post" action="{{ route('admin.test-lab.simulate', $order) }}">
                                    @csrf
                                    <input type="hidden" name="scenario" value="sms_only">
                                    <button class="flex w-full items-center justify-center gap-2 rounded-xl border border-line bg-panel px-3 py-2.5 text-xs font-semibold text-muted hover:bg-field"><span class="size-4 text-muted">{!! $icon('message') !!}</span> SMS only</button>
                                </form>
                                <form method="post" action="{{ route('admin.test-lab.simulate', $order) }}">
                                    @csrf
                                    <input type="hidden" name="scenario" value="notification_only">
                                    <button class="flex w-full items-center justify-center gap-2 rounded-xl border border-line bg-panel px-3 py-2.5 text-xs font-semibold text-muted hover:bg-field"><span class="size-4 text-muted">{!! $icon('bell') !!}</span> Notification only</button>
                                </form>
                            </div>
                        @else
                            <div class="mt-5 flex items-center gap-2 rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-2.5 text-xs font-semibold text-emerald-800"><span class="size-4">{!! $icon('check') !!}</span> Payment confirmed. This fixture is complete.</div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-line bg-field p-10 text-center">
                        <div class="mx-auto grid size-12 place-items-center rounded-2xl bg-blue-50 text-blue-700">{!! $icon('play') !!}</div>
                        <p class="mt-4 text-sm font-semibold text-slate-800">No test payments yet</p>
                        <p class="mt-1 text-sm text-muted">Create a payment on the left to begin a simulation.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
