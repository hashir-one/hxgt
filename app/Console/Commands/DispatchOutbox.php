<?php

namespace App\Console\Commands;

use App\Jobs\DeliverOrderEventWebhook;
use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchOutbox extends Command
{
    protected $signature = 'gateway:dispatch-outbox';

    protected $description = 'Dispatch pending event outbox messages to the queue';

    public function handle(): int
    {
        $messageIds = DB::transaction(function (): array {
            $query = OutboxMessage::query()
                ->whereNull('processed_at')
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit(100);

            $query->lock(DB::getDriverName() === 'pgsql' ? 'FOR UPDATE SKIP LOCKED' : true);
            $messages = $query->get();

            $messages->each(function (OutboxMessage $message): void {
                $message->update(['available_at' => now()->addMinutes(5)]);
            });

            return $messages->pluck('id')->all();
        });

        foreach ($messageIds as $messageId) {
            DeliverOrderEventWebhook::dispatch($messageId)->onQueue('events');
        }

        return self::SUCCESS;
    }
}
