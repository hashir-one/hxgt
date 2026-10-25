<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active')->index();
            $table->string('upi_vpa');
            $table->string('support_phone')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('default_locale')->default('en');
            $table->json('return_url_allowlist')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('merchant_id')->nullable()->after('id')->constrained('merchants')->nullOnDelete();
            $table->string('role')->default('owner')->after('email');
            $table->timestamp('last_login_at')->nullable();
            $table->index(['merchant_id', 'role']);
        });

        Schema::create('api_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_prefix', 32)->unique();
            $table->string('secret_hash');
            $table->json('scopes');
            $table->json('ip_allowlist')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_order_id');
            $table->unsignedBigInteger('requested_amount_minor');
            $table->unsignedBigInteger('payable_amount_minor');
            $table->unsignedTinyInteger('slot_paise');
            $table->string('currency', 3)->default('INR');
            $table->string('status')->default('pending')->index();
            $table->string('confirmation_method')->nullable();
            $table->string('checkout_token_hash')->unique();
            $table->string('return_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('late_payment_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'merchant_order_id']);
            $table->index(['merchant_id', 'payable_amount_minor', 'status']);
        });

        Schema::create('amount_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('base_amount_minor');
            $table->unsignedTinyInteger('slot_paise');
            $table->unsignedBigInteger('payable_amount_minor');
            $table->string('state')->default('held')->index();
            $table->timestamp('held_until')->index();
            $table->timestamps();
            $table->unique(['merchant_id', 'base_amount_minor', 'slot_paise']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->string('label')->nullable();
            $table->text('public_key')->unique();
            $table->string('attestation_status')->default('pending');
            $table->string('attestation_digest')->nullable();
            $table->string('status')->default('offline')->index();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->boolean('sms_listener_healthy')->default(false);
            $table->boolean('notification_listener_healthy')->default(false);
            $table->unsignedTinyInteger('consecutive_good_heartbeats')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'slot_number']);
        });

        Schema::create('device_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_number');
            $table->string('token_hash')->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_status')->default('pending');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('client_signal_id');
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference_number_normalized', 32)->nullable();
            $table->string('reference_number_hash')->nullable()->index();
            $table->timestamp('occurred_at');
            $table->timestamp('captured_at');
            $table->unsignedInteger('parser_rule_version')->nullable();
            $table->json('source_metadata')->nullable();
            $table->string('processing_status')->default('received')->index();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'client_signal_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number_normalized', 32)->unique();
            $table->string('reference_number_hash')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('INR');
            $table->timestamp('first_seen_at');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('unmatched')->index();
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence_number');
            $table->string('event_type');
            $table->json('payload');
            $table->timestamps();
            $table->unique(['order_id', 'sequence_number']);
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_signals');
        Schema::dropIfExists('device_pairings');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('amount_reservations');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('api_credentials');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['merchant_id']);
            $table->dropColumn(['merchant_id', 'role', 'last_login_at']);
        });
        Schema::dropIfExists('merchants');
    }
};
