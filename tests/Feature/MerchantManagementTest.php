<?php

namespace Tests\Feature;

use App\Domain\Devices\GatewayHealthService;
use App\Mail\OtpMail;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MerchantManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_onboard_a_merchant_with_owner_access_and_safe_defaults(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)->post(route('admin.merchants.store'), $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect();

        $merchant = Merchant::where('slug', 'acme-store')->firstOrFail();
        $this->assertSame(['shop.example.com'], $merchant->return_url_allowlist);
        $this->assertDatabaseHas('users', ['merchant_id' => $merchant->id, 'email' => 'owner@acme.example', 'role' => 'owner']);
        $this->assertDatabaseHas('merchant_settings', ['merchant_id' => $merchant->id, 'auto_confirm_order_cap_minor' => 100000]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'merchant.created', 'merchant_id' => $merchant->id, 'actor_id' => $admin->id]);
        $this->assertFalse(app(GatewayHealthService::class)->canAcceptOrders($merchant));
        $this->assertDatabaseCount('api_credentials', 0);
        $this->get(route('admin.merchants.show', $merchant))->assertOk()->assertSee('Acme Store');
        $this->get(route('admin.merchants.edit', $merchant))->assertOk();
        $this->get(route('admin.merchants.index', ['q' => 'acme']))->assertOk()->assertSee('Acme Store');
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Add merchant');
    }

    public function test_new_owner_can_request_a_login_code_and_access_the_panel(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)->post(route('admin.merchants.store'), $this->payload())->assertRedirect();
        $this->post(route('logout'));
        $this->post(route('login.request'), ['email' => 'owner@acme.example'])->assertRedirect(route('login'));
        Mail::assertQueued(OtpMail::class);
        $owner = User::where('email', 'owner@acme.example')->firstOrFail();
        $this->assertDatabaseHas('otp_challenges', ['user_id' => $owner->id, 'purpose' => 'login']);
        $this->actingAs($owner);
        foreach (['dashboard', 'payments', 'devices', 'developer', 'disputes', 'settings'] as $page) {
            $this->get(route('panel.'.$page))->assertOk();
        }
    }

    public function test_merchant_creation_requires_admin_and_does_not_trust_a_submitted_role(): void
    {
        $this->post(route('admin.merchants.store'), $this->payload())->assertRedirect(route('login'));
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->post(route('admin.merchants.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('merchants', 0);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)->post(route('admin.merchants.store'), $this->payload() + ['role' => 'admin', 'status' => 'suspended'])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'owner@acme.example', 'role' => 'owner']);
        $this->assertDatabaseHas('merchants', ['slug' => 'acme-store', 'status' => 'active']);
    }

    public function test_duplicate_owner_email_cannot_reassign_an_existing_account(): void
    {
        $existing = User::factory()->create(['email' => 'owner@acme.example', 'role' => 'admin']);
        $this->withSession(['mfa_user_id' => $existing->id, 'mfa_at' => now()->timestamp])->actingAs($existing)->post(route('admin.merchants.store'), $this->payload())->assertSessionHasErrors('owner_email');
        $this->assertDatabaseCount('merchants', 0);
        $this->assertSame('admin', $existing->fresh()->role);
        $this->assertNull($existing->fresh()->merchant_id);
    }

    public function test_onboarding_rejects_unsafe_domains_invalid_upi_and_inverted_limits(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = array_replace($this->payload(), ['return_domains' => '*.example.com', 'upi_vpa' => 'invalid', 'auto_confirm_day_cap_minor' => 50]);
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)->post(route('admin.merchants.store'), $payload)
            ->assertSessionHasErrors(['return_url_allowlist.0', 'upi_vpa', 'auto_confirm_day_cap_minor']);
        $this->assertDatabaseCount('merchants', 0);
    }

    public function test_admin_can_edit_suspend_and_reactivate_without_changing_the_owner(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)->post(route('admin.merchants.store'), $this->payload());
        $merchant = Merchant::firstOrFail();
        $this->put(route('admin.merchants.update', $merchant), array_replace($this->payload(), ['name' => 'Acme Updated', 'owner_email' => 'attacker@example.com']))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Acme Updated', $merchant->fresh()->name);
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
        $this->post(route('admin.merchants.suspend', $merchant))->assertRedirect();
        $this->assertSame('suspended', $merchant->fresh()->status);
        $this->post(route('admin.merchants.activate', $merchant))->assertRedirect();
        $this->assertSame('active', $merchant->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'merchant.updated', 'merchant_id' => $merchant->id]);
    }

    public function test_staff_and_suspended_owners_cannot_change_settings_or_issue_keys(): void
    {
        $merchant = Merchant::create(['name' => 'Store', 'slug' => 'store', 'upi_vpa' => 'store@kotak']);
        $staff = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'staff']);
        $this->actingAs($staff)->put(route('panel.settings.update'), ['support_phone' => '+919876543210'])->assertForbidden();
        $this->post(route('panel.keys.issue'), ['name' => 'Test', 'type' => 'create'])->assertForbidden();
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $merchant->update(['status' => 'suspended']);
        $this->actingAs($owner)->put(route('panel.settings.update'), ['support_phone' => '+919876543210'])->assertForbidden();
        $this->assertNull($merchant->fresh()->support_phone);
        $this->assertDatabaseCount('api_credentials', 0);
    }

    public function test_owner_support_update_is_scoped_and_audited(): void
    {
        $merchant = Merchant::create(['name' => 'Store', 'slug' => 'store', 'upi_vpa' => 'store@kotak']);
        $other = Merchant::create(['name' => 'Other', 'slug' => 'other', 'upi_vpa' => 'other@kotak']);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $this->actingAs($owner)->put(route('panel.settings.update'), ['support_phone' => '+919876543210', 'merchant_id' => $other->id])->assertRedirect();
        $this->assertSame('+919876543210', $merchant->fresh()->support_phone);
        $this->assertNull($other->fresh()->support_phone);
        $this->assertDatabaseHas('audit_logs', ['action' => 'merchant.support_updated', 'merchant_id' => $merchant->id]);
    }

    private function payload(): array
    {
        return ['name' => 'Acme Store', 'slug' => 'acme-store', 'upi_vpa' => 'acme@kotak', 'support_phone' => '+919876543210', 'return_domains' => 'SHOP.example.com', 'owner_name' => 'Acme Owner', 'owner_email' => 'Owner@acme.example', 'order_expiry_minutes' => 10, 'auto_confirm_order_cap_minor' => 100000, 'auto_confirm_day_cap_minor' => 1000000];
    }
}
