<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_credential_id')->nullable()->constrained('api_credentials')->nullOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['merchant_id', 'order_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_acknowledgements');
    }
};
