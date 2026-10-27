<?php

namespace Tests\Feature;

use App\Domain\Devices\PairingKeyRegistry;
use App\Domain\Devices\PendingKeyRecovery;
use App\Mail\OtpMail;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Merchant;
use App\Models\OtpChallenge;
use App\Models\PairingRecovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PendingKeyRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['gateway.device_domain' => 'https://device.example.test']);
    }

    private function owner(): User
    {
        $merchant = Merchant::create(['name' => 'Recovery Merchant', 'slug' => 'recovery-'.bin2hex(random_bytes(4)), 'upi_vpa' => 'test@kotak']);

        return User::factory()->create(['role' => 'owner', 'merchant_id' => $merchant->id]);
    }

    private function key(): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        return [$private, openssl_pkey_get_details($private)['key']];
    }

    private function authorize(User $owner, string $pem): array
    {
        $otp = OtpChallenge::create(['user_id' => $owner->id, 'email' => $owner->email,
            'purpose' => 'recover_device_key', 'challenge_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)]);

        return $this->actingAs($owner)->withSession(['pairing_recovery_otp_id' => $otp->id])
            ->postJson('/panel/devices/recovery/challenges', ['public_key' => $pem, 'otp' => '123456'])
            ->assertCreated()->json();
    }

    private function proof(array $challenge, \OpenSSLAsymmetricKey $key, ?string $message = null): array
    {
        openssl_sign($message ?? $challenge['signing_payload'], $signature, $key, OPENSSL_ALGO_SHA256);

        return ['recovery_id' => $challenge['recovery_id'], 'signature' => base64_encode($signature)];
    }

    private function registered(User $owner, string $pem, string $attestation = 'verified', bool $revoked = false): Device
    {
        $device = Device::create(['merchant_id' => $owner->merchant_id, 'slot_number' => 1,
            'public_key' => $pem, 'attestation_status' => $attestation,
            'status' => $revoked ? 'revoked' : 'offline', 'revoked_at' => $revoked ? now() : null]);
        DB::table('device_key_states')->insert([
            'spki_sha256' => PairingKeyRegistry::material($pem)['spki_sha256'],
            'device_id' => $device->id, 'created_at' => now(),
        ]);

        return $device;
    }

    public function test_legacy_key_without_token_gets_terminal_negative_only_after_valid_proof(): void
    {
        [$key, $pem] = $this->key();
        $owner = $this->owner();
        $challenge = $this->authorize($owner, $pem);
        $this->assertSame(0, DB::table('device_key_states')->count());
        $this->assertSame(300, $challenge['expires_unix'] - now()->timestamp);
        $expected = implode("\n", ['hx-pair-recovery-v1', 'POST', 'https://device.example.test',
            '/api/device/v1/pair/recovery', $challenge['recovery_id'], $owner->merchant->public_id,
            $challenge['spki_sha256'], 'key_all_pairings', 'resolve_and_close_if_absent',
            $challenge['nonce'], (string) $challenge['expires_unix']]);
        $this->assertSame($expected, $challenge['signing_payload']);
        $proof = $this->proof($challenge, $key);
        auth()->forgetGuards();

        $this->postJson(PendingKeyRecovery::PATH, $proof)->assertOk()
            ->assertJsonPath('outcome', 'not_registered_closed')->assertJsonPath('key_closed', true)
            ->assertJsonPath('registration', null)->assertJsonPath('spki_sha256', $challenge['spki_sha256'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertNotNull(DB::table('device_key_states')->sole()->closed_at);
        $this->assertNotNull(PairingRecovery::sole()->consumed_at);
        $this->assertDatabaseCount('devices', 0);
        $this->postJson(PendingKeyRecovery::PATH, $proof)->assertStatus(409)->assertJsonPath('error', 'RECOVERY_ALREADY_USED');
        $this->assertSame(1, AuditLog::where('action', 'device.recovery_resolved')->count());
        $audit = AuditLog::all()->toJson();
        foreach ([$pem, $challenge['nonce'], $proof['signature']] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
        $this->assertNull(AuditLog::where('action', 'device.recovery_resolved')->sole()->user_agent);
        $this->assertNull(AuditLog::where('action', 'device.recovery_resolved')->sole()->ip_address);
    }

    public static function registrations(): array
    {
        return [['verified', false, 'registered', true], ['submitted', false, 'registered', false], ['verified', true, 'revoked', false]];
    }

    #[DataProvider('registrations')]
    public function test_registered_key_returns_existing_identity_without_promoting_trust(string $attestation, bool $revoked, string $outcome, bool $activate): void
    {
        [$key, $pem] = $this->key();
        $owner = $this->owner();
        $device = $this->registered($owner, $pem, $attestation, $revoked);
        $before = $device->fresh()->getAttributes();
        $challenge = $this->authorize($owner, $pem);

        $this->postJson(PendingKeyRecovery::PATH, $this->proof($challenge, $key))->assertOk()
            ->assertJsonPath('outcome', $outcome)->assertJsonPath('key_closed', false)
            ->assertJsonPath('registration.device_id', $device->public_id)
            ->assertJsonPath('registration.attestation_status', $attestation)
            ->assertJsonPath('registration.can_activate', $activate);

        $this->assertSame($before, $device->fresh()->getAttributes());
        $this->assertNull(DB::table('device_key_states')->sole()->closed_at);
    }

    public function test_other_merchant_registration_is_never_disclosed_or_closed(): void
    {
        [$key, $pem] = $this->key();
        $device = $this->registered($this->owner(), $pem);
        $challenge = $this->authorize($this->owner(), $pem);

        $this->postJson(PendingKeyRecovery::PATH, $this->proof($challenge, $key))->assertNotFound()
            ->assertExactJson(['error' => 'RECOVERY_UNAVAILABLE'])->assertDontSee($device->public_id);

        $this->assertNull(PairingRecovery::sole()->consumed_at);
        $this->assertNull(DB::table('device_key_states')->sole()->closed_at);
    }

    public static function changedBindings(): array
    {
        return [['protocol'], ['method'], ['origin'], ['nonce'], ['scope'], ['id'], ['merchant'], ['key'], ['expiry'], ['path'], ['action'], ['newline'], ['wrong_key']];
    }

    #[DataProvider('changedBindings')]
    public function test_proof_binds_every_challenge_field(string $field): void
    {
        [$key, $pem] = $this->key();
        $challenge = $this->authorize($this->owner(), $pem);
        $lines = explode("\n", $challenge['signing_payload']);
        $index = ['protocol' => 0, 'method' => 1, 'origin' => 2, 'nonce' => 9, 'scope' => 7, 'id' => 4, 'merchant' => 5, 'key' => 6,
            'expiry' => 10, 'path' => 3, 'action' => 8][$field] ?? null;
        if ($index !== null) {
            $lines[$index] .= '-tampered';
        }
        if ($field === 'wrong_key') {
            [$key] = $this->key();
        }
        $message = implode("\n", $lines).($field === 'newline' ? "\n" : '');

        $this->postJson(PendingKeyRecovery::PATH, $this->proof($challenge, $key, $message))->assertUnauthorized()
            ->assertJsonPath('error', 'RECOVERY_PROOF_INVALID');

        $this->assertNull(PairingRecovery::sole()->consumed_at);
        $this->assertDatabaseCount('device_key_states', 0);
    }

    public function test_expiry_is_checked_after_proof_and_new_authorization_can_recover_again(): void
    {
        [$key, $pem] = $this->key();
        $owner = $this->owner();
        $challenge = $this->authorize($owner, $pem);
        $this->travel(5)->minutes();

        $this->postJson(PendingKeyRecovery::PATH, $this->proof($challenge, $key))->assertStatus(410)
            ->assertJsonPath('error', 'RECOVERY_EXPIRED');
        $this->assertDatabaseCount('device_key_states', 0);
        $next = $this->authorize($owner, $pem);
        $this->postJson(PendingKeyRecovery::PATH, $this->proof($next, $key))->assertOk()->assertJsonPath('outcome', 'not_registered_closed');
        $again = $this->authorize($owner, $pem);
        $this->postJson(PendingKeyRecovery::PATH, $this->proof($again, $key))->assertOk()->assertJsonPath('outcome', 'not_registered_closed');
        $this->assertDatabaseCount('device_key_states', 1);
    }

    public function test_failed_commit_returns_no_negative_and_rolls_back_nonce_and_fence(): void
    {
        [$key, $pem] = $this->key();
        $challenge = $this->authorize($this->owner(), $pem);
        DB::unprepared("CREATE TRIGGER fail_recovery_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'device.recovery_resolved'
            BEGIN SELECT RAISE(ABORT, 'synthetic recovery commit failure'); END");
        try {
            $this->postJson(PendingKeyRecovery::PATH, $this->proof($challenge, $key))->assertStatus(500);
            $this->assertDatabaseCount('device_key_states', 0);
            $this->assertNull(PairingRecovery::sole()->consumed_at);
            $this->assertNull(PairingRecovery::sole()->outcome);
        } finally {
            DB::unprepared('DROP TRIGGER fail_recovery_audit');
        }
        $this->postJson(PendingKeyRecovery::PATH, $this->proof($challenge, $key))->assertOk()
            ->assertJsonPath('outcome', 'not_registered_closed');
    }

    public static function revokedAuthorization(): array
    {
        return [['disabled'], ['role'], ['merchant'], ['suspended']];
    }

    #[DataProvider('revokedAuthorization')]
    public function test_owner_authorization_is_rechecked_at_resolution(string $change): void
    {
        [$key, $pem] = $this->key();
        $owner = $this->owner();
        $challenge = $this->authorize($owner, $pem);
        match ($change) {
            'disabled' => $owner->forceFill(['disabled_at' => now()])->save(),
            'role' => $owner->update(['role' => 'staff']),
            'merchant' => $owner->update(['merchant_id' => $this->owner()->merchant_id]),
            'suspended' => $owner->merchant->update(['status' => 'suspended']),
        };

        $this->postJson(PendingKeyRecovery::PATH, $this->proof($challenge, $key))->assertForbidden()
            ->assertJsonPath('error', 'RECOVERY_UNAVAILABLE');
        $this->assertDatabaseCount('device_key_states', 0);
        $this->assertNull(PairingRecovery::sole()->consumed_at);
    }

    public function test_owner_session_fresh_recovery_otp_and_second_factor_are_required(): void
    {
        [, $pem] = $this->key();
        $path = '/panel/devices/recovery/challenges';
        $data = ['public_key' => $pem, 'otp' => '123456'];
        $this->postJson($path, $data)->assertUnauthorized();
        $owner = $this->owner();
        $staff = User::factory()->create(['merchant_id' => $owner->merchant_id, 'role' => 'staff']);
        $this->actingAs($staff)->postJson($path, $data)->assertForbidden();
        $this->actingAs($owner)->postJson($path, $data)->assertUnprocessable();
        $other = $this->owner();
        foreach (['pair_device', 'recover_device_key'] as $purpose) {
            $otp = OtpChallenge::create(['user_id' => $purpose === 'pair_device' ? $owner->id : $other->id,
                'email' => $owner->email, 'purpose' => $purpose, 'challenge_hash' => Hash::make('123456'),
                'expires_at' => now()->addMinutes(5)]);
            $this->withSession(['pairing_recovery_otp_id' => $otp->id])->postJson($path, $data)->assertUnprocessable();
        }
        $this->assertDatabaseCount('pairing_recoveries', 0);
        $owner->forceFill(['totp_enabled_at' => now()])->save();
        $this->actingAs($owner)->postJson($path, $data)->assertRedirect(route('security.totp'));
    }

    public function test_recovery_otp_endpoint_sends_distinct_owner_code(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/panel/devices/recovery/otp')->assertStatus(202)->assertJsonPath('status', 'code_sent');
        $otp = OtpChallenge::sole();
        $this->assertSame('recover_device_key', $otp->purpose);
        $this->assertSame($owner->id, $otp->user_id);
        $this->assertSame($otp->id, session('pairing_recovery_otp_id'));
        Mail::assertQueued(OtpMail::class, 1);
    }

    public function test_otp_is_single_use_and_invalid_key_is_not_accepted(): void
    {
        [, $pem] = $this->key();
        $owner = $this->owner();
        $this->authorize($owner, $pem);
        $this->assertNotNull(OtpChallenge::sole()->consumed_at);
        $this->postJson('/panel/devices/recovery/challenges', ['public_key' => $pem, 'otp' => '123456'])->assertUnprocessable();
        $this->postJson('/panel/devices/recovery/challenges', ['public_key' => 'not a key', 'otp' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('public_key');
        $this->assertDatabaseCount('pairing_recoveries', 1);
    }

    public function test_origin_configuration_never_uses_request_host(): void
    {
        [, $pem] = $this->key();
        $this->withHeader('Host', 'attacker.example.test');
        $challenge = $this->authorize($this->owner(), $pem);
        $this->assertSame('https://device.example.test', $challenge['origin']);
        config(['gateway.device_domain' => 'http://device.example.test']);
        $this->expectException(HttpResponseException::class);
        app(PendingKeyRecovery::class)->origin();
    }

    public function test_migration_indexes_existing_noncanonical_and_revoked_p256_keys(): void
    {
        // This test owns the isolated in-memory database. Simulate the pre-migration schema.
        Schema::drop('pairing_recoveries');
        Schema::drop('device_key_states');
        [, $pem] = $this->key();
        $owner = $this->owner();
        $device = Device::create(['merchant_id' => $owner->merchant_id, 'slot_number' => 1,
            'public_key' => str_replace("\n", "\r\n", $pem), 'attestation_status' => 'submitted',
            'status' => 'revoked', 'revoked_at' => now()]);
        $before = $device->fresh()->getAttributes();

        $migration = require database_path('migrations/2026_10_27_082400_create_pending_key_recovery_tables.php');
        $migration->up();

        $this->assertSame($device->id, DB::table('device_key_states')->sole()->device_id);
        $this->assertSame(PairingKeyRegistry::material($pem)['spki_sha256'], DB::table('device_key_states')->sole()->spki_sha256);
        $this->assertSame($before, $device->fresh()->getAttributes());
    }
}
