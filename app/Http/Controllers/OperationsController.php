<?php

namespace App\Http\Controllers;

use App\Domain\Devices\ApprovalService;
use App\Domain\Orders\OrderEventService;
use App\Domain\Reconciliation\ReconciliationService;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Dispute;
use App\Models\EmailPaymentSignal;
use App\Models\Order;
use App\Models\ParserRule;
use App\Models\PaymentSignal;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsController extends Controller
{
    public function admin()
    {
        return view('admin.operations', [
            'deviceReviews' => Device::with('merchant')->whereNull('revoked_at')
                ->where('attestation_status', '!=', 'verified')->latest('id')
                ->paginate(25, ['*'], 'device_review_page')->withQueryString()->fragment('device-reviews'),
            'approvals' => Approval::with('merchant')->where('status', 'pending')->latest()->get(),
            'alerts' => DB::table('operational_alerts')->whereNull('resolved_at')->latest()->limit(100)->get(),
            'disputes' => Dispute::with('order.merchant')->whereNotNull('escalated_at')->where('status', 'open')->latest()->get(),
            'quarantine' => PaymentSignal::with('device.merchant')->whereIn('processing_status', ['needs_review', 'unmatched'])->latest()->limit(50)->get(),
            'rules' => ParserRule::latest('version')->get(),
            'parserPreset' => request()->query('parser_preset') === 'kotak-sms' ? config('bank_parsers.kotak_sms') : [],
            'emailEvidence' => EmailPaymentSignal::with('merchant')->latest()->limit(30)->get(),
            'failedDeliveries' => WebhookDelivery::with('merchant')->whereNotNull('failed_at')->latest()->limit(30)->get(),
            'deviceFaults' => DB::table('device_fault_reports')->join('devices', 'devices.id', '=', 'device_fault_reports.device_id')->join('merchants', 'merchants.id', '=', 'devices.merchant_id')->orderByDesc('device_fault_reports.id')->limit(50)->get(['device_fault_reports.*', 'merchants.name as merchant_name']),
            'audit' => AuditLog::latest('created_at')->paginate(50),
        ]);
    }

    public function vote(Request $request, Approval $approval, ApprovalService $service)
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required', 'string', 'max:500']]);
        $service->vote($approval, $request->user(), $data['decision'] === 'approve', $data['reason']);

        return back()->with('status', 'Approval decision recorded.');
    }

    public function parser(Request $request, AuditLogger $audit)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1', 'unique:parser_rules,version'],
            'sender_ids' => ['required', 'string', 'max:500'],
            'notification_packages' => ['required', 'string', 'max:500'],
            'amount_pattern' => ['required', 'string', 'max:500'],
            'reference_pattern' => ['required', 'string', 'max:500'],
            'schema' => ['nullable', 'in:hx-credit-re2-v1'],
            'credit_pattern' => ['required_if:schema,hx-credit-re2-v1', 'nullable', 'string', 'max:500'],
            'reject_pattern' => ['required_if:schema,hx-credit-re2-v1', 'nullable', 'string', 'max:500'],
            'notification_signing_sha256' => ['required_if:schema,hx-credit-re2-v1', 'nullable', 'json', 'max:10000'],
        ]);
        $senders = preg_split('/[\s,]+/', trim($data['sender_ids']));
        foreach ($senders as $sender) {
            abort_unless(preg_match('/^[A-Z]{2}-KOTAKB(?:-[A-Z])?$/', $sender), 422, 'Invalid bank sender ID');
        }
        $packages = preg_split('/[\s,]+/', trim($data['notification_packages']));
        foreach ($packages as $package) {
            abort_unless(preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $package), 422, 'Invalid Android package');
        }
        $rules = ['sender_ids' => $senders, 'notification_packages' => $packages, 'amount_pattern' => $data['amount_pattern'], 'reference_pattern' => $data['reference_pattern']];
        if (($data['schema'] ?? null) === 'hx-credit-re2-v1') {
            $certificates = json_decode($data['notification_signing_sha256'], true, flags: JSON_THROW_ON_ERROR);
            abort_unless(is_array($certificates) && array_diff(array_keys($certificates), $packages) === [], 422, 'Signing identities must match the allowed bank packages');
            foreach ($packages as $package) {
                $digests = $certificates[$package] ?? null;
                abort_unless(is_array($digests) && array_is_list($digests) && count($digests) >= 1 && count($digests) <= 8, 422, 'Each bank package needs verified signing digests');
                foreach ($digests as $digest) {
                    abort_unless(is_string($digest) && preg_match('/\A[a-f0-9]{64}\z/', $digest), 422, 'Bank signing digests must be lowercase SHA-256 hex');
                }
            }
            $rules += ['schema' => $data['schema'], 'credit_pattern' => $data['credit_pattern'], 'reject_pattern' => $data['reject_pattern'], 'notification_signing_sha256' => $certificates];
        }
        $rule = ParserRule::create(['version' => $data['version'], 'source' => 'kotak', 'status' => 'draft', 'created_by' => $request->user()->id, 'rules' => $rules]);
        $audit->record('parser_rule.created', null, ParserRule::class, $rule->id);

        return back()->with('status', 'Parser version saved as draft. Validate against real samples before activating.');
    }

    public function resolveAlert(Request $request, int $alert, AuditLogger $audit)
    {
        $record = DB::table('operational_alerts')->find($alert);
        abort_unless($record, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($alert, $record, $data, $audit): void {
            DB::table('operational_alerts')->where('id', $alert)->update(['resolved_at' => now()]);
            $audit->record('alert.resolved', $record->merchant_id, 'operational_alert', $alert, $data['reason']);
        });

        return back()->with('status', 'Alert resolved.');
    }

    public function reports(Request $request)
    {
        $merchant = $request->user()->merchant;

        return view('panel.reports', [
            'runs' => DB::table('reconciliation_runs')->where('merchant_id', $merchant->id)->latest()->paginate(20),
            'entries' => DB::table('reconciliation_entries')->whereIn('reconciliation_run_id', DB::table('reconciliation_runs')->where('merchant_id', $merchant->id)->select('id'))->latest()->limit(100)->get(),
            'alerts' => DB::table('operational_alerts')->where('merchant_id', $merchant->id)->whereNull('resolved_at')->latest()->get(),
        ]);
    }

    public function import(Request $request, ReconciliationService $reconciliation)
    {
        $data = $request->validate(['statement' => ['required', 'file', 'max:5120'], 'statement_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $reconciliation->import($request->user()->merchant, $request->file('statement')->getRealPath(), $data['statement_date'], $request->user()->id);

        return back()->with('status', 'Statement reconciled. Review the results below.');
    }

    public function team(Request $request)
    {
        abort_unless($request->user()->role === 'owner', 403);

        return view('panel.security', ['users' => $request->user()->merchant->users, 'logins' => AuditLog::where('merchant_id', $request->user()->merchant_id)->where('action', 'like', 'auth.%')->latest('created_at')->limit(50)->get()]);
    }

    public function addStaff(Request $request, AuditLogger $audit)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', 'unique:users,email']]);
        $user = $request->user()->merchant->users()->create($data + ['role' => 'staff', 'password' => Str::random(64)]);
        $audit->record('staff.created', $user->merchant_id, User::class, $user->id);

        return back()->with('status', 'Read-only staff account created. They can request a login code.');
    }

    public function disableStaff(Request $request, User $user, AuditLogger $audit)
    {
        abort_unless($user->merchant_id === $request->user()->merchant_id && $user->role === 'staff', 404);
        $user->forceFill(['disabled_at' => now()])->save();
        $audit->record('staff.disabled', $user->merchant_id, User::class, $user->id);

        return back()->with('status', 'Staff access disabled immediately.');
    }

    public function resend(Request $request, WebhookDelivery $delivery, AuditLogger $audit)
    {
        abort_unless($delivery->merchant_id === $request->user()->merchant_id, 404);
        DB::table('outbox_messages')->where('type', 'order.event')->where('aggregate_id', $delivery->order_event_id)->update(['processed_at' => null, 'available_at' => now()]);
        $audit->record('webhook.resend_requested', $delivery->merchant_id, WebhookDelivery::class, $delivery->id);

        return back()->with('status', 'Webhook queued for redelivery.');
    }

    public function refund(Request $request, Order $order, AuditLogger $audit)
    {
        abort_unless($order->merchant_id === $request->user()->merchant_id, 404);
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1'], 'bank_reference' => ['required', 'string', 'max:80', 'unique:refund_records,bank_reference'], 'reason' => ['required', 'string', 'max:500'], 'refunded_at' => ['required', 'date', 'before_or_equal:now']]);
        DB::transaction(function () use ($order, $data, $request, $audit): void {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->payment && in_array($locked->status, ['paid', 'late_payment', 'needs_review'], true), 422, 'No received payment to refund');
            $total = DB::table('refund_records')->where('order_id', $locked->id)->sum('amount_minor');
            abort_if($total + $data['amount_minor'] > $locked->payment->amount_minor, 422, 'Refund exceeds received payment');
            $id = DB::table('refund_records')->insertGetId($data + ['order_id' => $locked->id, 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            app(OrderEventService::class)->append($locked, 'refund.recorded', ['refund_id' => $id, 'refund_amount_minor' => $data['amount_minor']]);
            $audit->record('refund.recorded', $locked->merchant_id, Order::class, $locked->id, $data['reason']);
        });

        return back()->with('status', 'Manual refund recorded. No money was moved by the gateway.');
    }
}
