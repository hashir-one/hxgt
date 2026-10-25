<?php

namespace Tests\Feature;

use App\Http\Middleware\GatewayHeaders;
use App\Models\OtpChallenge;
use App\Models\SandboxTestOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_lab_is_unavailable_in_production_even_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->app->instance('env', 'production');
        config(['gateway.security.admin_ips' => ['127.0.0.1']]);
        $this->withoutMiddleware(GatewayHeaders::class);
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)->get(route('admin.test-lab'))->assertNotFound();
        $this->withSession(['_token' => 'csrf-test'])->post(route('admin.test-lab.create'), ['amount' => '500.00', '_token' => 'csrf-test'])->assertNotFound();
        $this->assertDatabaseCount('sandbox_test_orders', 0);
    }

    public function test_admin_otp_login_requires_a_second_factor_before_admin_access(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@test.example',
            'role' => 'admin',
            'password' => 'not-used',
        ]);
        $challenge = OtpChallenge::create([
            'user_id' => $admin->id,
            'email' => $admin->email,
            'purpose' => 'login',
            'challenge_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->withSession(['login_challenge_id' => $challenge->id])
            ->post(route('login.verify'), ['code' => '123456'])
            ->assertRedirect(route('security.totp'));
    }

    public function test_admin_can_run_an_isolated_two_source_sandbox_flow(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@test.example',
            'role' => 'admin',
            'password' => 'not-used',
        ]);

        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)
            ->post(route('admin.test-lab.create'), ['amount' => '500.00'])
            ->assertRedirect();

        $order = SandboxTestOrder::firstOrFail();
        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)
            ->post(route('admin.test-lab.signal', $order), ['source' => 'sms'])
            ->assertRedirect();
        $this->assertSame('detected', $order->fresh()->status);

        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)
            ->post(route('admin.test-lab.signal', $order), ['source' => 'notification'])
            ->assertRedirect();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('automatic', $order->fresh()->confirmation_method);
    }

    public function test_sandbox_email_path_requires_the_five_hundred_rupee_threshold(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@test.example',
            'role' => 'admin',
            'password' => 'not-used',
        ]);

        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)->post(route('admin.test-lab.create'), ['amount' => '499.00']);
        $order = SandboxTestOrder::firstOrFail();

        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)
            ->post(route('admin.test-lab.signal', $order), ['source' => 'email'])
            ->assertSessionHasErrors('source');
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_admin_can_simulate_a_complete_payment_with_one_action(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@test.example',
            'role' => 'admin',
            'password' => 'not-used',
        ]);

        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)->post(route('admin.test-lab.create'), ['amount' => '500.00']);
        $order = SandboxTestOrder::firstOrFail();

        $this->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp])->actingAs($admin)
            ->post(route('admin.test-lab.simulate', $order), ['scenario' => 'automatic'])
            ->assertRedirect();

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('automatic', $order->fresh()->confirmation_method);
        $this->assertCount(2, $order->fresh()->signals);
    }
}
