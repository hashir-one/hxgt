<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_key_states', function (Blueprint $table): void {
            $table->string('spki_sha256', 64)->primary();
            $table->foreignId('device_id')->nullable()->unique()->constrained('devices')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('pairing_recoveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('public_key');
            $table->string('spki_sha256', 64);
            $table->string('origin');
            $table->string('nonce', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
        });
        // Run under maintenance with all old pairing workers drained. Keep this historical
        // normalization self-contained: changes to runtime policy must not change a migration.
        DB::table('devices')->orderBy('id')->chunkById(200, function ($devices): void {
            foreach ($devices as $device) {
                $key = @openssl_pkey_get_public($device->public_key);
                $details = $key === false ? false : openssl_pkey_get_details($key);
                if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC
                    || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
                    continue;
                }
                $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $details['key']), true);
                // Never silently choose between multiple records for the same canonical key.
                DB::table('device_key_states')->insert([
                    'spki_sha256' => hash('sha256', $der), 'device_id' => $device->id,
                    'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Removing a terminal key fence would invalidate a receipt already held by a phone.
        throw new RuntimeException('Recovery fences are permanent; restore/rollback requires an explicit reconciliation plan.');
    }
};
