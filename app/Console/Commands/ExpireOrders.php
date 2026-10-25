<?php

namespace App\Console\Commands;

use App\Domain\Orders\OrderEventService;
use App\Models\Merchant;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireOrders extends Command
{
    protected $signature = 'gateway:expire-orders';

    protected $description = 'Persist expiry transitions and release only safe amount reservations';

    public function handle(OrderEventService $events): int
    {
        Order::query()
            ->whereIn('status', ['pending', 'detected'])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($events): void {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, $events): void {
                        Merchant::whereKey($order->merchant_id)->lockForUpdate()->firstOrFail();
                        $locked = Order::query()->lockForUpdate()->find($order->id);
                        if ($locked === null || ! in_array($locked->status, ['pending', 'detected'], true) || $locked->expires_at->isFuture()) {
                            return;
                        }
                        $locked->update(['status' => 'expired', 'expired_at' => now()]);
                        $locked->amountReservation?->update(['state' => 'quarantined']);
                        $events->append($locked, 'order.expired');
                        $breaker = $locked->merchant->circuitBreaker;
                        if ($breaker?->probe_order_id === $locked->id) {
                            $breaker->update([
                                'probe_order_id' => null,
                                'state' => 'open',
                                'cooldown_until' => now()->addMinutes(config('gateway.breaker.cooldown_minutes')),
                            ]);
                        }
                    });
                }
            });

        return self::SUCCESS;
    }
}
