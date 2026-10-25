<?php

namespace App\Domain\Sandbox;

use App\Models\SandboxTestOrder;
use App\Models\SandboxTestSignal;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SandboxTestService
{
    public function create(User $admin, int $baseAmountMinor): SandboxTestOrder
    {
        abort_unless(app()->environment('local', 'testing'), 404);

        if ($baseAmountMinor < 1) {
            throw new RuntimeException('INVALID_AMOUNT');
        }

        return SandboxTestOrder::create([
            'admin_user_id' => $admin->id,
            'merchant_id' => $admin->merchant_id,
            'merchant_order_id' => 'sandbox-'.Str::lower(Str::random(10)),
            'requested_amount_minor' => $baseAmountMinor,
            'payable_amount_minor' => $baseAmountMinor + 37,
            'currency' => 'INR',
            'reference_number_normalized' => $this->reference(),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    public function ingest(SandboxTestOrder $order, string $source): SandboxTestOrder
    {
        abort_unless(app()->environment('local', 'testing'), 404);

        if (! in_array($source, ['sms', 'notification', 'email'], true)) {
            throw new RuntimeException('INVALID_TEST_SOURCE');
        }

        return DB::transaction(fn (): SandboxTestOrder => $this->ingestLocked($order, $source));
    }

    public function simulate(SandboxTestOrder $order, string $scenario): SandboxTestOrder
    {
        abort_unless(app()->environment('local', 'testing'), 404);

        $sources = match ($scenario) {
            'automatic' => ['sms', 'notification'],
            'bank_email' => ['email'],
            'sms_only' => ['sms'],
            'notification_only' => ['notification'],
            default => throw new RuntimeException('INVALID_SIMULATION_SCENARIO'),
        };

        return DB::transaction(function () use ($order, $sources): SandboxTestOrder {
            $locked = SandboxTestOrder::query()->lockForUpdate()->findOrFail($order->id);

            foreach ($sources as $source) {
                $locked = $this->ingestLocked($locked, $source);
            }

            return $locked->load('signals');
        });
    }

    private function ingestLocked(SandboxTestOrder $order, string $source): SandboxTestOrder
    {
        if ($order->status === 'paid') {
            return $order->load('signals');
        }

        if ($source === 'email' && $order->payable_amount_minor < config('gateway.email.minimum_amount_minor')) {
            throw new RuntimeException('EMAIL_CONFIRMATION_MINIMUM_NOT_MET');
        }

        SandboxTestSignal::firstOrCreate([
            'sandbox_test_order_id' => $order->id,
            'source' => $source,
        ], [
            'amount_minor' => $order->payable_amount_minor,
            'reference_number_normalized' => $order->reference_number_normalized,
            'occurred_at' => now(),
        ]);

        $sources = $order->signals()->pluck('source')->all();
        $emailConfirmed = in_array('email', $sources, true) && $order->payable_amount_minor >= config('gateway.email.minimum_amount_minor');
        $twoSourceConfirmed = in_array('sms', $sources, true) && in_array('notification', $sources, true);

        if ($emailConfirmed || $twoSourceConfirmed) {
            $order->update([
                'status' => 'paid',
                'confirmation_method' => $emailConfirmed ? 'bank_email' : 'automatic',
                'paid_at' => now(),
            ]);
        } else {
            $order->update(['status' => 'detected']);
        }

        return $order->load('signals');
    }

    private function reference(): string
    {
        return Money::assertReference((string) random_int(100000000000, 999999999999));
    }
}
