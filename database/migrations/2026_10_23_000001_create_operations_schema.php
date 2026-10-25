<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('signal_id')->nullable()->constrained('payment_signals')->nullOnDelete();
            $table->string('result');
            $table->string('reason_code');
            $table->json('details')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->text('secret_encrypted');
            $table->string('status')->default('active');
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->timestamp('request_timestamp');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_body_hash')->nullable();
            $table->string('source_ip')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['order_event_id', 'attempt_number']);
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('reference_number_hash');
            $table->string('status')->default('open')->index();
            $table->unsignedTinyInteger('attempt_count')->default(1);
            $table->timestamp('captcha_passed_at')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'reference_number_hash']);
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained()->nullOnDelete();
            $table->string('claimed_reference_hash')->nullable();
            $table->json('failure_reasons')->nullable();
            $table->string('status')->default('open')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('merchant_decision')->nullable();
            $table->text('merchant_reason')->nullable();
            $table->string('admin_decision')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('circuit_breakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('state')->default('closed');
            $table->unsignedInteger('fault_count')->default(0);
            $table->unsignedInteger('consecutive_faults')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->unsignedInteger('trip_count_24h')->default(0);
            $table->timestamp('last_fault_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['merchant_id', 'created_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('request_hash');
            $table->string('response_type');
            $table->unsignedBigInteger('response_id');
            $table->json('response_payload');
            $table->timestamps();
            $table->unique(['merchant_id', 'key']);
        });

        Schema::create('device_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('nonce_hash');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['device_id', 'nonce_hash']);
        });

        Schema::create('parser_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('source')->default('kotak');
            $table->json('rules');
            $table->string('status')->default('draft');
            $table->timestamp('effective_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fault_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('severity')->default('system');
            $table->json('details')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->timestamp('available_at')->index();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('fault_events');
        Schema::dropIfExists('parser_rules');
        Schema::dropIfExists('device_nonces');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('circuit_breakers');
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('claims');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('match_attempts');
    }
};
