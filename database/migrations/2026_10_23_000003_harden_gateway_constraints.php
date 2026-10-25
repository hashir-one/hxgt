<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE amount_reservations DROP CONSTRAINT IF EXISTS amount_reservations_merchant_id_base_amount_minor_slot_paise_unique');
            DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS devices_merchant_id_slot_number_unique');
        } else {
            DB::statement('DROP INDEX IF EXISTS amount_reservations_merchant_id_base_amount_minor_slot_paise_unique');
            DB::statement('DROP INDEX IF EXISTS devices_merchant_id_slot_number_unique');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('merchant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('api_credentials', function (Blueprint $table): void {
            $table->string('type')->default('read')->after('name');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX amount_reservations_active_slot_unique ON amount_reservations (merchant_id, base_amount_minor, slot_paise) WHERE state <> \'released\'');
            DB::statement('CREATE UNIQUE INDEX devices_active_slot_unique ON devices (merchant_id, slot_number) WHERE revoked_at IS NULL');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_positive_amount CHECK (requested_amount_minor > 0 AND payable_amount_minor > 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_valid_slot CHECK (slot_paise BETWEEN 1 AND 99)');
            DB::statement('ALTER TABLE payment_signals ADD CONSTRAINT signals_valid_source CHECK (source IN (\'sms\', \'notification\'))');
            DB::statement('ALTER TABLE payment_signals ADD CONSTRAINT signals_positive_amount CHECK (amount_minor > 0)');
        } else {
            DB::statement('CREATE UNIQUE INDEX amount_reservations_active_slot_unique ON amount_reservations (merchant_id, base_amount_minor, slot_paise) WHERE state <> \'released\'');
            DB::statement('CREATE UNIQUE INDEX devices_active_slot_unique ON devices (merchant_id, slot_number) WHERE revoked_at IS NULL');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS amount_reservations_active_slot_unique');
        DB::statement('DROP INDEX IF EXISTS devices_active_slot_unique');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_positive_amount');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_valid_slot');
            DB::statement('ALTER TABLE payment_signals DROP CONSTRAINT IF EXISTS signals_valid_source');
            DB::statement('ALTER TABLE payment_signals DROP CONSTRAINT IF EXISTS signals_positive_amount');
        }

        Schema::table('api_credentials', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['merchant_id']);
            $table->dropColumn('merchant_id');
        });
    }
};
