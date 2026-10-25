<?php

namespace App\Http\Controllers;

use App\Domain\Sandbox\SandboxTestService;
use App\Models\SandboxTestOrder;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class AdminTestLabController extends Controller
{
    public function index()
    {
        return view('admin.test-lab', [
            'orders' => SandboxTestOrder::query()->with('signals')->latest()->limit(20)->get(),
            'emailMinimum' => Money::formatInr(config('gateway.email.minimum_amount_minor')),
        ]);
    }

    public function create(Request $request, SandboxTestService $sandbox, AuditLogger $audit)
    {
        $payload = $request->validate(['amount' => ['required', 'string', 'max:20']]);

        try {
            $amountMinor = Money::parseInr($payload['amount']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()])->withInput();
        }

        if ($amountMinor < 1 || $amountMinor > 100000000) {
            return back()->withErrors(['amount' => 'Enter an amount between ₹0.01 and ₹1,000,000.00.'])->withInput();
        }

        $order = $sandbox->create($request->user(), $amountMinor);
        $audit->record('sandbox.test_order_created', $request->user()->merchant_id, SandboxTestOrder::class, $order->id, metadata: ['sandbox' => true]);

        return back()->with('status', 'Test payment created. No live order or payment record was touched.');
    }

    public function signal(Request $request, SandboxTestOrder $order, SandboxTestService $sandbox, AuditLogger $audit)
    {
        abort_unless($order->admin_user_id === $request->user()->id, 403);
        $payload = $request->validate(['source' => ['required', 'in:sms,notification,email']]);

        try {
            $updated = $sandbox->ingest($order, $payload['source']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['source' => $exception->getMessage()]);
        }

        $audit->record('sandbox.test_signal_ingested', $request->user()->merchant_id, SandboxTestOrder::class, $order->id, metadata: ['source' => $payload['source'], 'status' => $updated->status]);

        return back()->with('status', 'Test '.$payload['source'].' signal accepted. Current status: '.$updated->status.'.');
    }

    public function simulate(Request $request, SandboxTestOrder $order, SandboxTestService $sandbox, AuditLogger $audit)
    {
        abort_unless($order->admin_user_id === $request->user()->id, 403);
        $payload = $request->validate(['scenario' => ['required', 'in:automatic,bank_email,sms_only,notification_only']]);

        try {
            $updated = $sandbox->simulate($order, $payload['scenario']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['scenario' => $exception->getMessage()]);
        }

        $audit->record('sandbox.test_payment_simulated', $request->user()->merchant_id, SandboxTestOrder::class, $order->id, metadata: [
            'scenario' => $payload['scenario'],
            'status' => $updated->status,
            'confirmation_method' => $updated->confirmation_method,
        ]);

        return back()->with('status', 'Payment simulation complete. Current status: '.$updated->status.'.');
    }
}
