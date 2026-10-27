<?php

namespace Tests\Feature;

use App\Domain\Devices\AndroidAttestationTrust;
use App\Domain\Devices\AttestationFailure;
use App\Domain\Devices\AttestationVerifier;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DevicePairing;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AndroidAttestationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00 UTC'));
        config([
            'attestation.enabled' => true, 'attestation.staging_identity_enabled' => true,
            'attestation.staging_package' => 'in.hxdev.capture.debug',
            'attestation.staging_signer_sha256' => 'a03c8ede4bafd2b22af3da3e5677de01ef5ff60411be27cbfb8b996ab8d07588',
            'attestation.min_os_version' => 130000, 'attestation.min_os_patch' => 202604,
            'attestation.min_vendor_patch' => 20260401, 'attestation.min_boot_patch' => 20260401,
            'attestation.max_patch_age_months' => 6,
            'attestation.staging_vendor_patch_exception.enabled' => false,
        ]);
        Http::preventStrayRequests();
        $this->trust();
    }

    private function fixture(string $name = 'valid'): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/AndroidAttestation/$name.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    private function trust(array $entries = [], array $headers = [], ?callable $duringFetch = null, array $responses = []): void
    {
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($entries, $headers, $duringFetch, $responses) {
            $duringFetch?->__invoke();
            if (array_key_exists($request->url(), $responses)) {
                return $responses[$request->url()];
            }
            $headers += ['Date' => now()->toRfc7231String(), 'Age' => '0', 'Cache-Control' => 'public, max-age=86400'];

            return match ($request->url()) {
                AndroidAttestationTrust::ROOTS_URL => Http::response([$this->fixture()['root']], 200, $headers),
                AndroidAttestationTrust::STATUS_URL => Http::response(['entries' => (object) $entries], 200, $headers),
                default => throw new \LogicException('Unexpected network request in isolated test'),
            };
        });
    }

    private function pairing(bool $migration = false): array
    {
        $merchant = Merchant::create(['name' => 'Synthetic Merchant', 'slug' => 'synthetic', 'upi_vpa' => 'synthetic@kotak']);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $old = $migration ? Device::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'public_key' => 'legacy-key',
            'attestation_status' => 'submitted', 'attestation_digest' => hash('sha256', 'legacy-digest-only')]) : null;
        $pairing = DevicePairing::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'requested_by' => $owner->id,
            'token_hash' => hash('sha256', $this->fixture()['token']), 'approval_status' => 'approved',
            'expires_at' => now()->addMinutes(5), 'replaces_device_id' => $old?->id]);

        return [$pairing, $old];
    }

    private function payload(string $fixture = 'valid'): array
    {
        $data = $this->fixture($fixture);

        return ['pairing_token' => $data['token'], 'public_key' => $data['public_key'], 'attestation_certificate_chain' => $data['certificates']];
    }

    public static function validChains(): array
    {
        return [['valid'], ['strongbox'], ['rkp']];
    }

    #[DataProvider('validChains')]
    public function test_real_verifier_accepts_synthetic_hardware_chains(string $fixture): void
    {
        [$pairing] = $this->pairing();
        $this->postJson('/api/device/v1/pair', $this->payload($fixture))->assertCreated()
            ->assertJsonPath('attestation_status', 'verified')->assertJsonPath('status', 'offline');
        $device = Device::sole();
        $this->assertSame('verified', $device->attestation_status);
        $this->assertSame(202610, $device->metadata['attestation']['os_patch']);
        $this->assertNotNull($pairing->fresh()->consumed_at);
        $this->assertSame('consumed', $pairing->fresh()->approval_status);
        $this->postJson('/api/device/v1/pair', $this->payload($fixture))->assertStatus(422)->assertJsonPath('error', 'PAIRING_NOT_AVAILABLE');
        $this->assertDatabaseCount('devices', 1);
        $this->assertSafeAudit();
    }

    public static function rejectedChains(): array
    {
        $cases = [
            'challenge' => 'CHALLENGE_MISMATCH', 'hex_challenge' => 'CHALLENGE_MISMATCH',
            'package' => 'APP_IDENTITY', 'signer' => 'APP_IDENTITY', 'shared_uid' => 'APP_IDENTITY', 'missing_app' => 'APP_IDENTITY',
            'software' => 'HARDWARE_POLICY', 'software_keymint' => 'HARDWARE_POLICY', 'imported' => 'HARDWARE_POLICY',
            'unlocked' => 'BOOT_POLICY', 'unverified_boot' => 'BOOT_POLICY', 'self_signed_boot' => 'BOOT_POLICY', 'missing_boot' => 'HARDWARE_POLICY',
            'old_os' => 'OS_POLICY', 'old_patch' => 'PATCH_POLICY', 'missing_vendor_patch' => 'PATCH_POLICY', 'missing_boot_patch' => 'PATCH_POLICY',
            'bad_patch_day' => 'PATCH_POLICY', 'future_patch' => 'PATCH_POLICY', 'software_patch' => 'HARDWARE_POLICY',
            'curve' => 'KEY_POLICY', 'key_size' => 'KEY_POLICY', 'no_sign' => 'KEY_POLICY', 'digest' => 'KEY_POLICY',
            'appended' => 'EXTENSION_POSITION', 'intermediate_extension' => 'EXTENSION_POSITION',
            'malformed_extension' => 'EXTENSION_INVALID', 'expired' => 'CERTIFICATE_TIME', 'bad_signature' => 'CHAIN_INVALID',
        ];

        return array_map(fn ($name, $code) => [$name, $code], array_keys($cases), array_values($cases));
    }

    #[DataProvider('rejectedChains')]
    public function test_bad_attestation_preserves_migration_and_safe_failure_audit(string $fixture, string $code): void
    {
        [$pairing, $old] = $this->pairing(true);
        $response = $this->withHeader('User-Agent', $this->fixture()['token'])->postJson('/api/device/v1/pair', $this->payload($fixture))
            ->assertStatus(422)->assertJson(['error' => 'ATTESTATION_REJECTED', 'code' => $code]);
        if ($code === 'PATCH_POLICY') {
            [$field, $reason] = match ($fixture) {
                'old_patch' => ['os_patch', 'BELOW_MINIMUM'],
                'missing_vendor_patch' => ['vendor_patch', 'MISSING'],
                'missing_boot_patch' => ['boot_patch', 'MISSING'],
                'bad_patch_day' => ['boot_patch', 'INVALID_DATE'],
                'future_patch' => ['os_patch', 'FUTURE'],
            };
            $response->assertJsonPath('details.field', $field)->assertJsonPath('details.reason', $reason)
                ->assertJsonPath('details.server_date', '2026-10-25')->assertJsonPath('details.max_age_months', 6);
            $this->assertSame($response->json('details'), AuditLog::sole()->metadata['details']);
            if ($fixture === 'old_patch') {
                $response->assertJsonPath('details.actual', '202501')->assertJsonPath('details.minimum', '202604');
            }
        } else {
            $response->assertExactJson(['error' => 'ATTESTATION_REJECTED', 'code' => $code]);
        }
        $this->assertPreserved($pairing, $old);
        $this->assertSame($code, AuditLog::sole()->reason);
        $this->assertSafeAudit();
    }

    public function test_key_binding_and_missing_chain_fail_closed(): void
    {
        [$pairing, $old] = $this->pairing(true);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $payload = $this->payload();
        $payload['public_key'] = openssl_pkey_get_details($key)['key'];
        $this->postJson('/api/device/v1/pair', $payload)->assertStatus(422)->assertJsonPath('code', 'PUBLIC_KEY_MISMATCH');
        $payload['attestation_certificate_chain'] = [];
        $this->postJson('/api/device/v1/pair', $payload)->assertStatus(422)->assertJsonPath('code', 'ATTESTATION_REQUIRED');
        $this->assertPreserved($pairing, $old);
    }

    public static function revokedCertificates(): array
    {
        return [[0, 'REVOKED'], [1, 'SUSPENDED'], [2, 'REVOKED'], [3, 'UNKNOWN']];
    }

    #[DataProvider('revokedCertificates')]
    public function test_every_certificate_is_checked_against_revocation(int $index, string $status): void
    {
        [$pairing, $old] = $this->pairing(true);
        $this->trust([$this->fixture()['serials'][$index] => ['status' => $status]]);
        $this->postJson('/api/device/v1/pair', $this->payload())->assertStatus(422)->assertJsonPath('code', 'CERTIFICATE_REVOKED');
        $this->assertPreserved($pairing, $old);
    }

    public static function badTrust(): array
    {
        return [['date'], ['age'], ['missing_date'], ['missing_cache'], ['unavailable_roots'], ['unavailable_status'], ['malformed'], ['empty_roots'], ['untrusted_root']];
    }

    #[DataProvider('badTrust')]
    public function test_bad_trust_information_fails_closed(string $case): void
    {
        [$pairing, $old] = $this->pairing(true);
        $headers = match ($case) {
            'date' => ['Date' => now()->subDays(2)->toRfc7231String()],
            'age' => ['Age' => '86400'], 'missing_date' => ['Date' => ''], 'missing_cache' => ['Cache-Control' => ''], default => [],
        };
        $this->trust(headers: $headers);
        if (str_starts_with($case, 'unavailable_')) {
            $url = $case === 'unavailable_roots' ? AndroidAttestationTrust::ROOTS_URL : AndroidAttestationTrust::STATUS_URL;
            $this->trust(responses: [$url => Http::failedConnection()]);
        } elseif (in_array($case, ['malformed', 'empty_roots', 'untrusted_root'], true)) {
            $body = match ($case) {
                'malformed' => ['entries' => ['invalid serial' => []]], 'empty_roots' => [],
                'untrusted_root' => [$this->certificatePem($this->fixture()['certificates'][1])],
            };
            $this->trust(responses: [($case === 'malformed' ? AndroidAttestationTrust::STATUS_URL : AndroidAttestationTrust::ROOTS_URL) => Http::response($body, 200,
                ['Date' => now()->toRfc7231String(), 'Cache-Control' => 'max-age=86400'])]);
        }
        $expected = match ($case) {
            'unavailable_roots', 'unavailable_status' => 'TRUST_UNAVAILABLE', 'malformed', 'empty_roots' => 'TRUST_DATA_INVALID',
            'untrusted_root' => 'ROOT_UNTRUSTED', default => 'TRUST_STALE',
        };
        $this->postJson('/api/device/v1/pair', $this->payload())->assertStatus($case === 'untrusted_root' ? 422 : 503)->assertJsonPath('code', $expected);
        $this->assertPreserved($pairing, $old);
    }

    public static function pairingRaces(): array
    {
        return [['expiry'], ['approval'], ['consumed'], ['token'], ['merchant']];
    }

    #[DataProvider('pairingRaces')]
    public function test_pairing_is_rechecked_after_slow_verification(string $case): void
    {
        [$pairing, $old] = $this->pairing(true);
        $this->trust(duringFetch: function () use ($pairing, $case) {
            match ($case) {
                'expiry' => $pairing->update(['expires_at' => now()]),
                'approval' => $pairing->update(['approval_status' => 'pending']),
                'consumed' => $pairing->update(['consumed_at' => now()]),
                'token' => $pairing->update(['token_hash' => hash('sha256', 'changed')]),
                'merchant' => $pairing->merchant->update(['status' => 'suspended']),
            };
        });
        $this->postJson('/api/device/v1/pair', $this->payload())->assertStatus(422);
        $this->assertNull($old->fresh()->revoked_at);
        $this->assertDatabaseCount('devices', 1);
        $this->assertSame(0, AuditLog::whereIn('action', ['device.paired', 'device.migrated'])->count());
    }

    public function test_successful_migration_is_atomic_and_duplicate_key_failure_rolls_back(): void
    {
        [$pairing, $old] = $this->pairing(true);
        $duplicate = Device::create(['merchant_id' => $pairing->merchant_id, 'slot_number' => 2, 'public_key' => $this->fixture()['public_key']]);
        $this->postJson('/api/device/v1/pair', $this->payload())->assertStatus(422);
        $this->assertNull($old->fresh()->revoked_at);
        $this->assertNull($pairing->fresh()->consumed_at);
        $this->assertDatabaseCount('audit_logs', 0);
        // Isolated test-only row; no deployed device or outbox is modified.
        $duplicate->delete();
        $this->postJson('/api/device/v1/pair', $this->payload())->assertCreated()->assertJsonPath('attestation_status', 'verified');
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertSame('submitted', $old->fresh()->attestation_status);
        $this->assertNotNull($pairing->fresh()->consumed_at);
        $this->assertDatabaseCount('devices', 2);
        $this->assertSame(2, AuditLog::whereIn('action', ['device.paired', 'device.migrated'])->count());
    }

    public function test_debug_identity_requires_explicit_staging_and_never_becomes_release_identity(): void
    {
        foreach ([
            ['attestation.staging_identity_enabled' => false],
            ['app.env' => 'production', 'attestation.release_package' => null, 'attestation.release_signer_sha256' => null],
            ['app.env' => 'production', 'attestation.release_package' => 'in.hxdev.capture.debug', 'attestation.release_signer_sha256' => str_repeat('b', 64)],
            ['app.env' => 'production', 'attestation.release_package' => 'in.hxdev.capture', 'attestation.release_signer_sha256' => config('attestation.staging_signer_sha256')],
        ] as $settings) {
            config(['app.env' => 'testing', 'attestation.staging_identity_enabled' => true]);
            config($settings);
            try {
                app(AttestationVerifier::class)->policy();
                $this->fail('Unsafe identity configuration accepted');
            } catch (AttestationFailure $e) {
                $this->assertSame('ATTESTATION_NOT_CONFIGURED', $e->failureCode);
            }
        }
        config(['app.env' => 'production', 'attestation.release_package' => 'in.hxdev.capture', 'attestation.release_signer_sha256' => str_repeat('c', 64)]);
        $this->assertSame('in.hxdev.capture', app(AttestationVerifier::class)->policy()['package']);
    }

    private function vendorException(string $fixture = 'old_vendor'): void
    {
        $der = preg_replace('/-----[^-]+-----|\s/', '', $this->fixture($fixture)['public_key']);
        config([
            'app.env' => 'staging',
            'attestation.staging_vendor_patch_exception' => [
                'enabled' => true, 'spki_sha256' => hash('sha256', base64_decode($der, true)),
                'min_vendor_patch' => 20260201, 'max_age_months' => 8,
            ],
        ]);
    }

    public function test_staging_exception_accepts_pinned_key_and_records_effective_vendor_policy(): void
    {
        [$pairing] = $this->pairing();
        $this->vendorException();

        $this->postJson('/api/device/v1/pair', $this->payload('old_vendor'))->assertCreated();

        $evidence = Device::sole()->metadata['attestation'];
        $this->assertSame(202608, $evidence['os_patch']);
        $this->assertSame(20260201, $evidence['vendor_patch']);
        $this->assertSame(['scope' => 'staging_attested_key', 'minimum' => 20260201, 'max_age_months' => 8], $evidence['vendor_patch_exception']);
        $this->assertSame($evidence, AuditLog::where('action', 'device.paired')->sole()->metadata);
        $this->assertNotNull($pairing->fresh()->consumed_at);
        $this->assertSafeAudit();
    }

    public static function vendorExceptionBoundaries(): array
    {
        return [
            'disabled' => [['attestation.staging_vendor_patch_exception.enabled' => false], '20260401', 6, 'BELOW_MINIMUM'],
            'different key' => [['attestation.staging_vendor_patch_exception.spki_sha256' => str_repeat('0', 64)], '20260401', 6, 'BELOW_MINIMUM'],
            'testing is not staging' => [['app.env' => 'testing'], '20260401', 6, 'BELOW_MINIMUM'],
            'minimum still enforced' => [['attestation.staging_vendor_patch_exception.min_vendor_patch' => 20260301], '20260301', 8, 'BELOW_MINIMUM'],
            'age still enforced' => [['attestation.staging_vendor_patch_exception.max_age_months' => 7], '20260201', 7, 'TOO_OLD'],
        ];
    }

    #[DataProvider('vendorExceptionBoundaries')]
    public function test_vendor_exception_boundaries_reject_with_422_and_preserve_pairing(array $settings, string $minimum, int $maxAge, string $reason): void
    {
        [$pairing, $old] = $this->pairing(true);
        $this->vendorException();
        config($settings);

        $this->postJson('/api/device/v1/pair', $this->payload('old_vendor'))->assertStatus(422)
            ->assertJsonPath('code', 'PATCH_POLICY')->assertJsonPath('details.field', 'vendor_patch')
            ->assertJsonPath('details.minimum', $minimum)->assertJsonPath('details.max_age_months', $maxAge)
            ->assertJsonPath('details.reason', $reason);

        $this->assertPreserved($pairing, $old);
    }

    public function test_vendor_exception_ages_out_at_next_calendar_month(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 00:00:00 UTC'));
        [$pairing, $old] = $this->pairing(true);
        $this->vendorException();

        $this->postJson('/api/device/v1/pair', $this->payload('old_vendor'))->assertStatus(422)
            ->assertJsonPath('details.field', 'vendor_patch')->assertJsonPath('details.reason', 'TOO_OLD')
            ->assertJsonPath('details.max_age_months', 8)->assertJsonPath('details.server_date', '2026-11-01');

        $this->assertPreserved($pairing, $old);
    }

    public static function productionVendorPolicy(): array
    {
        return [
            'minimum' => [20260401, 'BELOW_MINIMUM'],
            'maximum age' => [20260101, 'TOO_OLD'],
        ];
    }

    #[DataProvider('productionVendorPolicy')]
    public function test_production_ignores_staging_exception_even_with_matching_key_and_release_identity(int $minimum, string $reason): void
    {
        [$pairing, $old] = $this->pairing(true);
        $this->vendorException('release_old_vendor');
        config(['app.env' => 'production', 'attestation.release_package' => 'in.hxdev.capture',
            'attestation.release_signer_sha256' => str_repeat('c', 64), 'attestation.min_vendor_patch' => $minimum]);

        $this->postJson('/api/device/v1/pair', $this->payload('release_old_vendor'))->assertStatus(422)
            ->assertJsonPath('code', 'PATCH_POLICY')->assertJsonPath('details.field', 'vendor_patch')
            ->assertJsonPath('details.minimum', (string) $minimum)->assertJsonPath('details.max_age_months', 6)
            ->assertJsonPath('details.reason', $reason);

        $this->assertPreserved($pairing, $old);
    }

    public static function invalidVendorExceptions(): array
    {
        return [
            'missing pin' => ['spki_sha256', null], 'wildcard pin' => ['spki_sha256', '*'],
            'missing date' => ['min_vendor_patch', null], 'invalid date' => ['min_vendor_patch', 20260230],
            'missing age' => ['max_age_months', null], 'unbounded age' => ['max_age_months', 13],
            'negative age' => ['max_age_months', -1],
        ];
    }

    #[DataProvider('invalidVendorExceptions')]
    public function test_invalid_enabled_vendor_exception_fails_closed_with_503(string $field, mixed $value): void
    {
        [$pairing, $old] = $this->pairing(true);
        $this->vendorException();
        config(["attestation.staging_vendor_patch_exception.$field" => $value]);

        $this->postJson('/api/device/v1/pair', $this->payload('old_vendor'))->assertStatus(503)
            ->assertJsonPath('code', 'ATTESTATION_NOT_CONFIGURED');

        $this->assertPreserved($pairing, $old);
    }

    public static function otherChecksWithVendorException(): array
    {
        return [
            ['challenge', 'CHALLENGE_MISMATCH', null, null],
            ['signer', 'APP_IDENTITY', null, null],
            ['software', 'HARDWARE_POLICY', null, null],
            ['unlocked', 'BOOT_POLICY', null, null],
            ['digest', 'KEY_POLICY', null, null],
            ['bad_signature', 'CHAIN_INVALID', null, null],
            ['old_os', 'OS_POLICY', null, null],
            ['old_patch', 'PATCH_POLICY', 'os_patch', 'BELOW_MINIMUM'],
            ['stale_os', 'PATCH_POLICY', 'os_patch', 'TOO_OLD'],
            ['stale_boot', 'PATCH_POLICY', 'boot_patch', 'TOO_OLD'],
            ['missing_vendor_patch', 'PATCH_POLICY', 'vendor_patch', 'MISSING'],
            ['bad_vendor_day', 'PATCH_POLICY', 'vendor_patch', 'INVALID_DATE'],
            ['bad_vendor_format', 'PATCH_POLICY', 'vendor_patch', 'INVALID_FORMAT'],
            ['future_vendor', 'PATCH_POLICY', 'vendor_patch', 'FUTURE'],
        ];
    }

    #[DataProvider('otherChecksWithVendorException')]
    public function test_vendor_exception_keeps_other_checks_enforced(string $fixture, string $code, ?string $field, ?string $reason): void
    {
        [$pairing, $old] = $this->pairing(true);
        $this->vendorException($fixture);
        // Isolate age checks from fixed minimum dates.
        if (in_array($fixture, ['stale_os', 'stale_boot'], true)) {
            config(['attestation.min_os_patch' => 202601, 'attestation.min_boot_patch' => 20260101]);
        }

        $response = $this->postJson('/api/device/v1/pair', $this->payload($fixture))->assertStatus(422)->assertJsonPath('code', $code);

        if ($field !== null) {
            $response->assertJsonPath('details.field', $field)->assertJsonPath('details.reason', $reason)
                ->assertJsonPath('details.max_age_months', $field === 'vendor_patch' ? 8 : 6);
        }
        $this->assertPreserved($pairing, $old);
    }

    public function test_vendor_exception_still_requires_chain_key_binding(): void
    {
        [$pairing, $old] = $this->pairing(true);
        $this->vendorException();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $payload = $this->payload('old_vendor');
        $payload['public_key'] = openssl_pkey_get_details($key)['key'];
        $der = preg_replace('/-----[^-]+-----|\s/', '', $payload['public_key']);
        config(['attestation.staging_vendor_patch_exception.spki_sha256' => hash('sha256', base64_decode($der, true))]);

        $this->postJson('/api/device/v1/pair', $payload)->assertStatus(422)->assertJsonPath('code', 'PUBLIC_KEY_MISMATCH');

        $this->assertPreserved($pairing, $old);
    }

    public function test_request_cannot_enable_vendor_exception(): void
    {
        [$pairing, $old] = $this->pairing(true);
        $this->vendorException();
        config(['attestation.staging_vendor_patch_exception.enabled' => false]);
        $payload = $this->payload('old_vendor') + [
            'staging_vendor_patch_exception' => array_replace(config('attestation.staging_vendor_patch_exception'), ['enabled' => true]),
            'min_vendor_patch' => 20260201, 'vendor_max_patch_age_months' => 8,
        ];

        $this->postJson('/api/device/v1/pair', $payload)->assertStatus(422)
            ->assertJsonPath('code', 'PATCH_POLICY')->assertJsonPath('details.minimum', '20260401')
            ->assertJsonPath('details.max_age_months', 6);

        $this->assertPreserved($pairing, $old);
    }

    private function certificatePem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split($der, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    private function assertPreserved(DevicePairing $pairing, Device $old): void
    {
        $this->assertNull($old->fresh()->revoked_at);
        $this->assertSame('submitted', $old->fresh()->attestation_status);
        $this->assertNull($pairing->fresh()->consumed_at);
        $this->assertSame('approved', $pairing->fresh()->approval_status);
        $this->assertDatabaseCount('devices', 1);
    }

    private function assertSafeAudit(): void
    {
        $json = AuditLog::all()->toJson();
        $this->assertStringNotContainsString($this->fixture()['token'], $json);
        $this->assertStringNotContainsString($this->fixture()['certificates'][0], $json);
        $this->assertStringNotContainsString('PUBLIC KEY', $json);
        $this->assertNull(AuditLog::first()->user_agent);
        $this->assertNull(AuditLog::first()->ip_address);
    }
}
