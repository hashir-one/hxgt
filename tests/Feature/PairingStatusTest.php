<?php

namespace Tests\Feature;

use App\Domain\Devices\AttestationFailure;
use App\Models\AuditLog;
use App\Models\DevicePairing;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PairingStatusTest extends TestCase
{
    use RefreshDatabase;

    private function pairing(): array
    {
        $merchant = Merchant::create(['name' => 'Pairing Status Test', 'slug' => 'pairing-status-test', 'upi_vpa' => 'test@kotak']);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $pairing = DevicePairing::create(['merchant_id' => $merchant->id, 'slot_number' => 1, 'requested_by' => $owner->id,
            'token_hash' => hash('sha256', 'private-test-token'), 'approval_status' => 'approved', 'expires_at' => now()->addMinutes(5)]);

        return [$owner, $pairing];
    }

    public function test_owner_sees_rejection_in_panel_and_status_without_secrets(): void
    {
        [$owner, $pairing] = $this->pairing();
        AuditLog::create(['action' => 'device.attestation_rejected', 'actor_type' => 'system', 'merchant_id' => $owner->merchant_id,
            'target_type' => DevicePairing::class, 'target_id' => $pairing->id, 'reason' => 'PATCH_POLICY', 'created_at' => now(),
            'metadata' => ['private_blob' => 'private-test-token', 'details' => ['field' => 'vendor_patch', 'reason' => 'BELOW_MINIMUM',
                'actual' => '20250101', 'minimum' => 20260401, 'max_age_months' => 6, 'server_date' => '2026-10-25', 'token' => 'private-test-token']]]);
        $response = $this->actingAs($owner)->getJson(route('panel.devices.pairing-status', $pairing))
            ->assertOk()->assertJsonPath('state', 'rejected')->assertJsonPath('code', 'PATCH_POLICY')
            ->assertJsonPath('details.field', 'vendor_patch')->assertJsonPath('details.actual', '20250101')
            ->assertJsonPath('details.minimum', '20260401')->assertJsonPath('poll', true);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertDontSee('private-test-token')->assertDontSee($pairing->token_hash)->assertJsonMissingPath('details.token');
        $this->get(route('panel.devices'))->assertOk()->assertSee('Scan received, but attestation was rejected.')
            ->assertSee('PATCH_POLICY')->assertSee('20250101')->assertDontSee('private-test-token');
        $this->assertNull($pairing->fresh()->consumed_at);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_pairing_status_cannot_be_read_by_other_merchants_staff_or_guests(): void
    {
        [$owner, $pairing] = $this->pairing();
        $url = route('panel.devices.pairing-status', $pairing);
        $this->getJson($url)->assertUnauthorized();
        $staff = User::factory()->create(['merchant_id' => $owner->merchant_id, 'role' => 'staff']);
        $this->actingAs($staff)->getJson($url)->assertForbidden();
        $otherMerchant = Merchant::create(['name' => 'Other Merchant', 'slug' => 'other-merchant', 'upi_vpa' => 'other@kotak']);
        $otherOwner = User::factory()->create(['merchant_id' => $otherMerchant->id, 'role' => 'owner']);
        $this->actingAs($otherOwner)->getJson($url)->assertNotFound();
    }

    public static function lifecycle(): array
    {
        return [['waiting'], ['expired'], ['unavailable'], ['paired']];
    }

    #[DataProvider('lifecycle')]
    public function test_polling_stops_when_pairing_is_no_longer_available(string $state): void
    {
        [$owner, $pairing] = $this->pairing();
        match ($state) {
            'expired' => $pairing->update(['expires_at' => now()->subSecond()]),
            'unavailable' => $pairing->update(['approval_status' => 'rejected']),
            'paired' => $pairing->update(['consumed_at' => now(), 'approval_status' => 'consumed']),
            default => null,
        };
        $this->actingAs($owner)->getJson(route('panel.devices.pairing-status', $pairing))->assertOk()
            ->assertJsonPath('state', $state)->assertJsonPath('poll', $state === 'waiting')->assertJsonPath('code', null);
    }

    public function test_patch_diagnostics_allow_only_bounded_public_policy_fields(): void
    {
        $details = ['field' => 'os_patch', 'reason' => 'FUTURE', 'actual' => ['private-data'],
            'minimum' => 'private-data', 'server_date' => 'private-data', 'max_age_months' => 1000, 'token' => 'private-data'];
        $failure = new AttestationFailure('PATCH_POLICY', details: $details);
        $this->assertSame(['field' => 'os_patch', 'reason' => 'FUTURE'], $failure->details);
        $this->assertSame([], (new AttestationFailure('KEY_POLICY', details: $details))->details);
        $this->assertSame([], AttestationFailure::safePatchDetails(['field' => 'serial_number', 'reason' => 'MISSING']));
    }
}
