<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('totp_last_counter')->nullable();
            $table->timestamp('disabled_at')->nullable();
        });
        Schema::table('orders', fn (Blueprint $table) => $table->unsignedTinyInteger('claim_attempts')->default(0));
        Schema::table('api_credentials', fn (Blueprint $table) => $table->text('signing_secret_encrypted')->nullable());
        Schema::table('device_pairings', fn (Blueprint $table) => $table->foreignId('replaces_device_id')->nullable()->constrained('devices'));
        Schema::table('disputes', fn (Blueprint $table) => $table->timestamp('escalated_at')->nullable());
        Schema::table('payment_signals', fn (Blueprint $table) => $table->timestamp('fault_recorded_at')->nullable());
        Schema::create('api_nonces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_credential_id')->constrained()->cascadeOnDelete();
            $table->string('nonce_hash');
            $table->timestamp('expires_at');
            $table->unique(['api_credential_id', 'nonce_hash']);
        });
        Schema::create('operational_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained();
            $table->string('deduplication_key')->unique();
            $table->string('type');
            $table->text('message');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->date('statement_date');
            $table->string('file_hash');
            $table->string('status')->default('review');
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['merchant_id', 'file_hash']);
        });
        Schema::create('reconciliation_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_run_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->string('reference_hash');
            $table->unsignedBigInteger('amount_minor');
            $table->string('result');
            $table->timestamps();
        });
        Schema::create('refund_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('recorded_by')->constrained('users');
            $table->unsignedBigInteger('amount_minor');
            $table->string('bank_reference')->unique();
            $table->text('reason');
            $table->timestamp('refunded_at');
            $table->timestamps();
        });
        foreach (['audit_logs', 'order_events'] as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION reject_immutable_change() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'History is append-only'; END; $$ LANGUAGE plpgsql");
                DB::unprepared("CREATE TRIGGER {$name}_immutable BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION reject_immutable_change()");
            } elseif (DB::getDriverName() === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $action) {
                    DB::unprepared("CREATE TRIGGER {$name}_immutable_{$action} BEFORE {$action} ON {$name} BEGIN SELECT RAISE(ABORT, 'History is append-only'); END");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'order_events'] as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}_immutable ON {$name}");
            } else {
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}_immutable_UPDATE");
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}_immutable_DELETE");
            }
        }
        foreach (['refund_records', 'reconciliation_entries', 'reconciliation_runs', 'operational_alerts', 'api_nonces'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['totp_last_counter', 'disabled_at']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('claim_attempts'));
        Schema::table('api_credentials', fn (Blueprint $table) => $table->dropColumn('signing_secret_encrypted'));
        Schema::table('device_pairings', fn (Blueprint $table) => $table->dropConstrainedForeignId('replaces_device_id'));
        Schema::table('disputes', fn (Blueprint $table) => $table->dropColumn('escalated_at'));
        Schema::table('payment_signals', fn (Blueprint $table) => $table->dropColumn('fault_recorded_at'));
    }
};
