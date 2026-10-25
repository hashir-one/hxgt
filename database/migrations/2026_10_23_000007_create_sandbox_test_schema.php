<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandbox_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('merchant_order_id', 128);
            $table->unsignedBigInteger('requested_amount_minor');
            $table->unsignedBigInteger('payable_amount_minor');
            $table->string('currency', 3)->default('INR');
            $table->string('reference_number_normalized', 32);
            $table->string('status')->default('pending')->index();
            $table->string('confirmation_method')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['admin_user_id', 'created_at']);
        });

        Schema::create('sandbox_test_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sandbox_test_order_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference_number_normalized', 32);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['sandbox_test_order_id', 'source']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sandbox_test_orders ADD CONSTRAINT sandbox_orders_valid_status CHECK (status IN ('pending', 'detected', 'paid'))");
            DB::statement("ALTER TABLE sandbox_test_signals ADD CONSTRAINT sandbox_signals_valid_source CHECK (source IN ('sms', 'notification', 'email'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sandbox_test_orders DROP CONSTRAINT IF EXISTS sandbox_orders_valid_status');
            DB::statement('ALTER TABLE sandbox_test_signals DROP CONSTRAINT IF EXISTS sandbox_signals_valid_source');
        }

        Schema::dropIfExists('sandbox_test_signals');
        Schema::dropIfExists('sandbox_test_orders');
    }
};
