<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class AlertService
{
    public function raise(?int $merchantId, string $type, string $message, string $key): void
    {
        DB::table('operational_alerts')->insertOrIgnore([
            'merchant_id' => $merchantId, 'deduplication_key' => $key,
            'type' => $type, 'message' => $message, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
