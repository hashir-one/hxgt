<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MerchantPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_must_sign_in_to_preview(): void
    {
        $this->get(route('panel.preview'))->assertRedirect(route('login'));
        $this->get(route('admin.preview'))->assertRedirect(route('login'));
    }

    public function test_owner_can_preview_on_staging_without_creating_payment_records(): void
    {
        $this->app->instance('env', 'staging');
        Http::preventStrayRequests();
        Queue::fake();
        $merchant = $this->merchant();
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);

        $this->actingAs($owner)->get(route('panel.preview'))
            ->assertOk()->assertSee('Preview &amp; test', false)->assertSee($merchant->name)
            ->assertSee('Demo mode')->assertSee('Successful payment')
            ->assertDontSee('upi://', false)->assertDontSee($merchant->upi_vpa);

        foreach (['orders', 'payments', 'order_events', 'payment_signals', 'webhook_deliveries', 'sandbox_test_orders'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
    }

    public function test_merchant_query_parameters_cannot_select_another_store(): void
    {
        $merchant = $this->merchant();
        $other = Merchant::create(['name' => 'Other Private Store', 'slug' => 'other', 'upi_vpa' => 'other@kotak']);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);

        $this->actingAs($owner)->get(route('panel.preview', ['merchant' => $other->id, 'merchant_id' => $other->id]))
            ->assertOk()->assertViewHas('merchant', fn ($value) => $value->id === $merchant->id)
            ->assertDontSee('Other Private Store');
        $this->get(route('admin.merchants.preview', $other))->assertForbidden();
    }

    public function test_admin_must_complete_mfa_before_previewing_a_merchant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $merchant = $this->merchant();

        $this->actingAs($admin)->get(route('admin.merchants.preview', $merchant))->assertRedirect(route('security.totp'));
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])
            ->get(route('admin.merchants.preview', $merchant))->assertOk()->assertSee($merchant->name);
        $this->get(route('admin.preview'))->assertOk()->assertSee('Your store');
        $this->assertDatabaseCount('merchants', 1);
    }

    public function test_staff_can_use_read_only_preview_but_disabled_users_cannot(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'merchant_id' => $this->merchant()->id]);

        $this->actingAs($staff)->get(route('panel.preview'))->assertOk();
        $staff->forceFill(['disabled_at' => now()])->save();
        $this->get(route('panel.preview'))->assertForbidden();
    }

    public function test_preview_requires_a_merchant_and_respects_enrolled_mfa(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->get(route('panel.preview'))->assertForbidden();
        $owner->forceFill(['merchant_id' => $this->merchant()->id, 'totp_enabled_at' => now()])->save();
        $this->get(route('panel.preview'))->assertRedirect(route('security.totp'));
    }

    public function test_preview_escapes_branding_and_does_not_render_unsafe_logo_paths(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private.txt', 'PRIVATE-LOGO-CONTENT');
        $merchant = $this->merchant();
        $merchant->update(['name' => '<script>alert(1)</script>', 'support_phone' => '<img src=x onerror=alert(2)>', 'logo_path' => 'merchant-logos/../private.txt']);
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $merchant->id]);

        $this->actingAs($owner)->get(route('panel.preview'))->assertOk()
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<img src=x onerror=alert(2)>')->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertViewHas('logo', null)->assertDontSee('PRIVATE-LOGO-CONTENT');
    }

    public function test_preview_displays_the_merchants_uploaded_logo(): void
    {
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMioAAAAASUVORK5CYII=');
        Storage::disk('local')->put('merchant-logos/store.png', $png);
        $merchant = $this->merchant();
        $merchant->update(['logo_path' => 'merchant-logos/store.png']);
        $owner = User::factory()->create(['role' => 'owner', 'merchant_id' => $merchant->id]);

        $this->actingAs($owner)->get(route('panel.preview'))->assertOk()
            ->assertViewHas('logo', 'data:image/png;base64,'.base64_encode($png));
    }

    private function merchant(): Merchant
    {
        return Merchant::create(['name' => 'Preview Store', 'slug' => 'preview-store', 'upi_vpa' => 'private-real-vpa@kotak']);
    }
}
