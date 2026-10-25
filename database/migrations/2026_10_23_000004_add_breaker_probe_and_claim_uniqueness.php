<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuit_breakers', function (Blueprint $table): void {
            $table->foreignId('probe_order_id')->nullable()->after('cooldown_until')->constrained('orders')->nullOnDelete();
        });

        Schema::table('claims', function (Blueprint $table): void {
            $table->unique(['order_id', 'reference_number_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table): void {
            $table->dropUnique(['order_id', 'reference_number_hash']);
        });
        Schema::table('circuit_breakers', function (Blueprint $table): void {
            $table->dropForeign(['probe_order_id']);
            $table->dropColumn('probe_order_id');
        });
    }
};
