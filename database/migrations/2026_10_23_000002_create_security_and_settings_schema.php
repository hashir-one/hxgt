<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret_encrypted')->nullable();
            $table->timestamp('totp_enabled_at')->nullable();
        });

        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('purpose');
            $table->string('challenge_hash');
            $table->timestamp('expires_at')->index();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->json('context')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['email', 'purpose', 'created_at']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->string('status')->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('second_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('merchant_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('order_expiry_minutes')->default(10);
            $table->unsignedSmallInteger('heartbeat_timeout_seconds')->default(90);
            $table->unsignedTinyInteger('healthy_heartbeats_to_resume')->default(2);
            $table->unsignedTinyInteger('breaker_consecutive_threshold')->default(3);
            $table->unsignedTinyInteger('breaker_rolling_threshold')->default(5);
            $table->unsignedTinyInteger('breaker_window_minutes')->default(15);
            $table->unsignedSmallInteger('breaker_cooldown_minutes')->default(10);
            $table->unsignedTinyInteger('dispute_escalation_hours')->default(24);
            $table->unsignedBigInteger('auto_confirm_order_cap_minor')->default(100000);
            $table->unsignedBigInteger('auto_confirm_day_cap_minor')->default(1000000);
            $table->string('regional_locale')->default('mr');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_settings');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('otp_challenges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['totp_secret_encrypted', 'totp_enabled_at']);
        });
    }
};
