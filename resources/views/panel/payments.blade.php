@extends('layouts.app')

@section('content')
<div class="flex flex-wrap items-end justify-between gap-5">
    <div>
        <p class="eyebrow">Payments</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight text-strong sm:text-4xl">Order ledger</h1>
        <p class="mt-2 text-sm text-muted">Every order, state transition and confirmation method in one place.</p>
    </div>
    <a href="{{ route('panel.payments.export', request()->query()) }}" class="rounded-2xl border border-line bg-field px-4 py-3 text-sm font-bold text-strong hover:border-line hover:bg-field">Download CSV <span class="ml-1 text-accent">↓</span></a>
</div>

<form class="surface-muted mt-7 grid gap-3 rounded-2xl p-4 md:grid-cols-[1.3fr_1fr_1fr_1fr_auto] md:items-end">
    <label class="text-xs font-semibold text-muted">Order ID<input name="order_id" value="{{ request('order_id') }}" placeholder="Search merchant order" class="mt-2 w-full rounded-xl border border-line bg-field px-3 py-2.5 text-sm text-strong outline-none focus:border-cyan-300/60"></label>
    <label class="text-xs font-semibold text-muted">State<select name="status" class="mt-2 w-full rounded-xl border border-line bg-field px-3 py-2.5 text-sm text-strong outline-none focus:border-cyan-300/60"><option value="">All states</option>@foreach(['pending','detected','paid','expired','needs_review','late_payment'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select></label>
    <label class="text-xs font-semibold text-muted">From<input type="date" name="from" value="{{ request('from') }}" class="mt-2 w-full rounded-xl border border-line bg-field px-3 py-2.5 text-sm text-strong outline-none focus:border-cyan-300/60"></label>
    <label class="text-xs font-semibold text-muted">To<input type="date" name="to" value="{{ request('to') }}" class="mt-2 w-full rounded-xl border border-line bg-field px-3 py-2.5 text-sm text-strong outline-none focus:border-cyan-300/60"></label>
    <label class="field-label">Min amount (paise)<input class="field" type="number" min="0" name="min_amount_minor" value="{{ request('min_amount_minor') }}"></label><label class="field-label">Max amount (paise)<input class="field" type="number" min="0" name="max_amount_minor" value="{{ request('max_amount_minor') }}"></label>
    <button class="rounded-xl bg-panel px-4 py-2.5 text-sm font-bold text-strong hover:bg-cyan-50">Apply</button>
</form>

<div class="surface mt-6 overflow-hidden rounded-[28px]"><div class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="border-b border-line bg-field text-[11px] uppercase tracking-[.14em] text-muted"><tr><th class="px-5 py-4">Order</th><th class="px-5 py-4">Amount</th><th class="px-5 py-4">State</th><th class="px-5 py-4">Verification</th><th class="px-5 py-4">Created</th></tr></thead><tbody class="divide-y divide-line">
@forelse($orders as $order)
<tr class="hover:bg-field"><td class="px-5 py-4"><a class="font-semibold text-accent" href="{{ route('panel.payments.show', $order) }}">{{ $order->merchant_order_id }}</a><p class="mt-1 font-mono text-[11px] text-muted">{{ $order->public_id }}</p></td><td class="px-5 py-4 font-semibold text-strong">₹{{ number_format($order->payable_amount_minor / 100, 2) }}</td><td class="px-5 py-4"><span class="status-pill {{ $order->status === 'paid' ? 'bg-emerald-300/10 text-positive' : (in_array($order->status, ['needs_review','late_payment'], true) ? 'bg-amber-300/10 text-warning' : 'bg-field text-muted') }}">{{ str_replace('_', ' ', $order->status) }}</span></td><td class="px-5 py-4 text-muted">{{ $order->confirmation_method ? str_replace('_', ' ', $order->confirmation_method) : '—' }}</td><td class="px-5 py-4 text-muted">{{ $order->created_at->toDayDateTimeString() }}</td></tr>
@empty
<tr><td colspan="5" class="px-5 py-14 text-center"><p class="text-sm font-semibold text-strong">No matching orders</p><p class="mt-2 text-sm text-muted">Try changing the filters or create a new order through the API.</p></td></tr>
@endforelse
</tbody></table></div></div>
<div class="mt-5">{{ $orders->links() }}</div>
@endsection
