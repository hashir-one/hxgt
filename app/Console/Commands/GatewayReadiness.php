<?php

namespace App\Console\Commands;

use App\Models\ParserRule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GatewayReadiness extends Command
{
    protected $signature = 'gateway:readiness';

    protected $description = 'Check production configuration without changing data or sending payments';

    public function handle(): int
    {
        $checks = [];
        $checks['Production mode / debug disabled'] = app()->environment('production') && ! config('app.debug');
        $checks['Encryption key'] = filled(config('app.key'));
        $checks['Queued delivery (not sync)'] = in_array(config('queue.default'), ['database', 'redis'], true);
        $checks['Persistent cache for locks'] = in_array(config('cache.default'), ['database', 'redis'], true);
        $checks['Secure, encrypted cookies'] = config('session.secure') && config('session.encrypt') && config('session.http_only');
        $checks['Transactional mail transport'] = ! in_array(config('mail.default'), ['log', 'array', null], true);
        $checks['Turnstile keys and hostname allowlist'] = filled(config('gateway.claims.captcha_secret')) && filled(config('gateway.claims.captcha_site_key')) && count(config('gateway.claims.captcha_hosts')) > 0;
        $checks['Admin network allowlist'] = count(config('gateway.security.admin_ips')) > 0;
        $domains = [];
        foreach (['admin_domain', 'api_domain', 'device_domain', 'checkout_domain'] as $surface) {
            $url = config('gateway.'.$surface);
            $checks[$surface.' uses HTTPS'] = parse_url($url, PHP_URL_SCHEME) === 'https' && filled(parse_url($url, PHP_URL_HOST));
            $domains[] = parse_url($url, PHP_URL_HOST);
        }
        $checks['Four distinct entry-point hosts'] = count(array_unique($domains)) === 4;
        try {
            DB::connection()->getPdo();
            $checks['PostgreSQL connection'] = DB::getDriverName() === 'pgsql';
            $checks['Two enrolled administrators'] = User::where('role', 'admin')->whereNull('disabled_at')->whereNotNull('totp_enabled_at')->count() >= 2;
            $rule = ParserRule::where('status', 'active')->where('source', 'kotak')->latest('version')->first();
            $checks['Active bank parser allowlists (samples require separate validation)'] = filled($rule?->rules['sender_ids'] ?? []) && filled($rule?->rules['notification_packages'] ?? []);
            $checks['Legacy API keys rotated'] = ! DB::table('api_credentials')->whereNull('revoked_at')->whereNull('signing_secret_encrypted')->exists();
            $checks['Gateway operations and integrity migrations'] = DB::table('migrations')->whereIn('migration', ['2026_10_23_000009_complete_gateway_operations', '2026_10_24_025905_protect_confirmed_order_state', '2026_10_24_031016_create_device_fault_reports_table'])->count() === 3;
            $checks['No seeded demo merchant'] = ! DB::table('merchants')->where('slug', 'demo-merchant')->exists();
        } catch (\Throwable) {
            $checks['Database / gateway schema available'] = false;
        }
        $this->table(['Check', 'Result'], collect($checks)->map(fn ($passed, $name) => [$name, $passed ? 'PASS' : 'REQUIRED'])->all());
        $this->line('External acceptance still required: bank sample validation, Android attestation integration, actual mail/webhook delivery, TLS/WAF perimeter, backup restore, concurrency/load and pilot tests.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
