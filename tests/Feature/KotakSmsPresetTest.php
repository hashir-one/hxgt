<?php

namespace Tests\Feature;

use App\Models\ParserRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KotakSmsPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_load_sms_preset_and_save_a_draft_without_activating_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'totp_secret_encrypted' => 'synthetic-test-secret']);
        $this->actingAs($admin)->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp]);
        $this->get(route('admin.operations', ['parser_preset' => 'kotak-sms']))
            ->assertOk()->assertSee('SMS preset loaded.')->assertViewHas('parserPreset', config('bank_parsers.kotak_sms'));
        $this->assertDatabaseCount('parser_rules', 0);

        $this->post(route('admin.parser-rules.store'), config('bank_parsers.kotak_sms') + [
            'version' => 1, 'sender_ids' => 'VM-KOTAKB', 'notification_packages' => 'com.example.bank',
            'notification_signing_sha256' => json_encode(['com.example.bank' => [str_repeat('a', 64)]]),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $rule = ParserRule::sole();
        $this->assertSame('draft', $rule->status);
        $this->assertSame(config('bank_parsers.kotak_sms.credit_pattern'), $rule->rules['credit_pattern']);
    }

    public function test_preset_does_not_supply_missing_source_identities(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'totp_secret_encrypted' => 'synthetic-test-secret']);
        $this->actingAs($admin)->withSession(['mfa_user_id' => $admin->id, 'mfa_at' => now()->timestamp]);
        $this->post(route('admin.parser-rules.store'), config('bank_parsers.kotak_sms') + ['version' => 1])
            ->assertSessionHasErrors(['sender_ids', 'notification_packages', 'notification_signing_sha256']);
        $this->assertDatabaseCount('parser_rules', 0);
    }
}
