<?php

namespace Database\Seeders;

use App\Models\CircuitBreaker;
use App\Models\Merchant;
use App\Models\MerchantSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo seeding is disabled outside local/testing. Use gateway:create-admin.');
        }

        $merchant = Merchant::updateOrCreate(['slug' => 'demo-merchant'], [
            'public_id' => (string) Str::uuid(),
            'name' => 'HX Demo Merchant',
            'upi_vpa' => 'merchant@kotak',
            'support_phone' => '+91 90000 00000',
            'return_url_allowlist' => ['localhost', '127.0.0.1'],
        ]);

        User::updateOrCreate(['email' => env('DEMO_OWNER_EMAIL', 'owner@example.com')], [
            'merchant_id' => $merchant->id,
            'name' => 'Merchant Owner',
            'role' => 'owner',
            'password' => 'not-used-with-otp',
        ]);

        User::updateOrCreate(['email' => env('ADMIN_EMAIL', 'admin@example.com')], [
            'name' => 'Platform Admin',
            'role' => 'admin',
            'password' => 'not-used-with-otp',
        ]);

        MerchantSetting::updateOrCreate(['merchant_id' => $merchant->id], []);
        CircuitBreaker::updateOrCreate(['merchant_id' => $merchant->id], ['state' => 'closed']);
    }
}
