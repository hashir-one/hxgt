<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hx_verified_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway_order_id')->unique();
            $table->unsignedBigInteger('event_id')->unique();
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('reference_hash', 64);
            $table->timestamp('verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hx_verified_payments');
    }
};
