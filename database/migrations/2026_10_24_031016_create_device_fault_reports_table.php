<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_fault_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained();
            $table->uuid('client_fault_id');
            $table->string('type');
            $table->string('source');
            $table->unsignedInteger('parser_rule_version')->nullable();
            $table->timestamp('occurred_at');
            $table->string('payload_hash', 64);
            $table->timestamps();
            $table->unique(['device_id', 'client_fault_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_fault_reports');
    }
};
