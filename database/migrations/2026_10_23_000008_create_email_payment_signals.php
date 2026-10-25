<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_payment_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('message_id_hash')->unique();
            $table->string('sender_email');
            $table->string('subject_hash')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference_number_normalized', 32);
            $table->string('reference_number_hash')->index();
            $table->timestamp('occurred_at');
            $table->json('authentication')->nullable();
            $table->unsignedInteger('parser_rule_version')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('received')->index();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'reference_number_hash']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE email_payment_signals ADD CONSTRAINT email_signals_positive_amount CHECK (amount_minor > 0)');
            DB::statement("ALTER TABLE email_payment_signals ADD CONSTRAINT email_signals_valid_status CHECK (status IN ('received', 'matched', 'late_payment', 'needs_review', 'duplicate'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE email_payment_signals DROP CONSTRAINT IF EXISTS email_signals_positive_amount');
            DB::statement('ALTER TABLE email_payment_signals DROP CONSTRAINT IF EXISTS email_signals_valid_status');
        }

        Schema::dropIfExists('email_payment_signals');
    }
};
