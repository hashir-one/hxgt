<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuit_breakers', function (Blueprint $table): void {
            $table->timestamp('trip_window_started_at')->nullable()->after('trip_count_24h');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->foreignId('webhook_endpoint_id')
                ->nullable()
                ->after('order_event_id')
                ->constrained('webhook_endpoints')
                ->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS payments_order_unique ON payments (order_id) WHERE order_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS claims_reference_unique ON claims (reference_number_hash)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS outbox_type_aggregate_unique ON outbox_messages (type, aggregate_id)');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_positive_amount CHECK (amount_minor > 0)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_inr_currency CHECK (currency = 'INR')");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_inr_currency CHECK (currency = 'INR')");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_valid_status CHECK (status IN ('pending', 'detected', 'paid', 'expired', 'late_payment', 'needs_review'))");
            DB::statement('ALTER TABLE devices ADD CONSTRAINT devices_valid_slot CHECK (slot_number BETWEEN 1 AND 4)');
            DB::statement('ALTER TABLE device_pairings ADD CONSTRAINT pairings_valid_slot CHECK (slot_number BETWEEN 1 AND 4)');
            DB::statement("ALTER TABLE amount_reservations ADD CONSTRAINT reservations_valid_state CHECK (state IN ('held', 'quarantined', 'released'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_valid_status CHECK (status IN ('unmatched', 'confirmed', 'late'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_positive_amount');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_inr_currency');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_inr_currency');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_valid_status');
            DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS devices_valid_slot');
            DB::statement('ALTER TABLE device_pairings DROP CONSTRAINT IF EXISTS pairings_valid_slot');
            DB::statement('ALTER TABLE amount_reservations DROP CONSTRAINT IF EXISTS reservations_valid_state');
            DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_valid_status');
        }

        DB::statement('DROP INDEX IF EXISTS payments_order_unique');
        DB::statement('DROP INDEX IF EXISTS claims_reference_unique');
        DB::statement('DROP INDEX IF EXISTS outbox_type_aggregate_unique');

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropForeign(['webhook_endpoint_id']);
            $table->dropColumn('webhook_endpoint_id');
        });

        Schema::table('circuit_breakers', function (Blueprint $table): void {
            $table->dropColumn('trip_window_started_at');
        });
    }
};
