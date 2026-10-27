<?php

namespace Tests\Integration;

use App\Domain\Devices\AndroidAttestationTrust;
use App\Domain\Devices\PendingKeyRecovery;
use App\Models\DevicePairing;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Opt-in: only a disposable PostgreSQL cluster in /tmp; never the application database. */
class PendingKeyRecoveryConcurrencyTest extends TestCase
{
    private mixed $app;

    private string $schema;

    private string $socket;

    private string $directory;

    private array $children = [];

    protected function setUp(): void
    {
        $socket = getenv('HX_RECOVERY_PG_SOCKET');
        if (! is_string($socket) || ! preg_match('#\A/tmp/hx-recovery-pg-[a-zA-Z0-9_-]+\z#', $socket)
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires disposable HX_RECOVERY_PG_SOCKET under /tmp and pcntl.');
        }
        $this->socket = $socket;
        $this->schema = 'recovery_test_'.bin2hex(random_bytes(8));
        $this->directory = sys_get_temp_dir().'/'.$this->schema;
        mkdir($this->directory, 0700);
        $this->connection()->exec('CREATE SCHEMA '.$this->schema);
        $this->app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $this->app->make(ConsoleKernel::class)->bootstrap();
        config([
            'app.env' => 'testing', 'app.debug' => false, 'cache.default' => 'array', 'session.driver' => 'array',
            'queue.default' => 'sync', 'mail.default' => 'array',
            'database.default' => 'pgsql', 'database.connections.pgsql.url' => null,
            'database.connections.pgsql.host' => $socket, 'database.connections.pgsql.port' => 55439,
            'database.connections.pgsql.database' => 'hx_recovery_test', 'database.connections.pgsql.username' => 'postgres',
            'database.connections.pgsql.password' => '', 'database.connections.pgsql.search_path' => $this->schema,
            'database.connections.pgsql.sslmode' => 'disable', 'gateway.device_domain' => 'https://device.example.test',
            'attestation.enabled' => true, 'attestation.staging_identity_enabled' => true,
            'attestation.staging_package' => 'in.hxdev.capture.debug',
            'attestation.staging_signer_sha256' => 'a03c8ede4bafd2b22af3da3e5677de01ef5ff60411be27cbfb8b996ab8d07588',
            'attestation.min_os_version' => 130000, 'attestation.min_os_patch' => 202604,
            'attestation.min_vendor_patch' => 20260401, 'attestation.min_boot_patch' => 20260401,
            'attestation.max_patch_age_months' => 6, 'attestation.staging_vendor_patch_exception.enabled' => false,
        ]);
        DB::purge('pgsql');
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00 UTC'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }

    private function connection(): PDO
    {
        return new PDO("pgsql:host={$this->socket};port=55439;dbname=hx_recovery_test", 'postgres', '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        if (! isset($this->schema)) {
            return;
        }
        file_put_contents($this->directory.'/release', '1');
        foreach ($this->children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
        DB::disconnect('pgsql');
        $this->connection()->exec('DROP SCHEMA '.$this->schema.' CASCADE');
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        Carbon::setTestNow();
        HandleExceptions::flushState($this);
        $this->app->flush();
    }

    private function await(callable $condition, int $seconds = 15): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($condition()) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Timed out waiting for PostgreSQL concurrency barrier.');
    }

    private function child(string $name, string $path, array $body, bool $hold): void
    {
        DB::disconnect('pgsql');
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork test process.');
        }
        if ($pid === 0) {
            try {
                DB::purge('pgsql');
                DB::statement("SET application_name = '".$this->schema.'_'.$name."'");
                DB::statement("SET lock_timeout = '12s'");
                if ($hold) {
                    $paused = false;
                    DB::listen(function (QueryExecuted $event) use (&$paused): void {
                        if (! $paused && str_contains($event->sql, 'device_key_states') && str_contains($event->sql, 'for update')) {
                            $paused = true;
                            file_put_contents($this->directory.'/locked', '1');
                            $this->await(fn () => is_file($this->directory.'/release'));
                        }
                    });
                }
                $request = Request::create('https://device.example.test'.$path, 'POST', server: [
                    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                ], content: json_encode($body, JSON_THROW_ON_ERROR));
                $response = $this->app->make(HttpKernel::class)->handle($request);
                file_put_contents($this->directory.'/'.$name.'.json', json_encode([
                    'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true),
                ], JSON_THROW_ON_ERROR));
                DB::disconnect('pgsql');
                exit(0);
            } catch (\Throwable $e) {
                file_put_contents($this->directory.'/'.$name.'.json', json_encode(['test_error' => $e->getMessage()]));
                exit(1);
            }
        }
        $this->children[] = $pid;
    }

    public static function ordering(): array
    {
        return ['recovery commits first' => [true], 'pairing commits first' => [false]];
    }

    #[DataProvider('ordering')]
    public function test_real_pairing_and_recovery_serialize_on_the_first_use_of_a_key(bool $recoveryFirst): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/AndroidAttestation/recovery_test.json')), true, flags: JSON_THROW_ON_ERROR);
        Http::preventStrayRequests();
        Http::fake([
            AndroidAttestationTrust::ROOTS_URL => Http::response([$fixture['root']], 200,
                ['Date' => now()->toRfc7231String(), 'Cache-Control' => 'max-age=86400']),
            AndroidAttestationTrust::STATUS_URL => Http::response(['entries' => (object) []], 200,
                ['Date' => now()->toRfc7231String(), 'Cache-Control' => 'max-age=86400']),
        ]);
        $merchant = Merchant::create(['name' => 'Concurrency Test', 'slug' => 'concurrency', 'upi_vpa' => 'test@kotak']);
        $owner = User::factory()->create(['merchant_id' => $merchant->id, 'role' => 'owner']);
        $pairing = DevicePairing::create(['merchant_id' => $merchant->id, 'slot_number' => 1,
            'requested_by' => $owner->id, 'token_hash' => hash('sha256', $fixture['token']),
            'approval_status' => 'approved', 'expires_at' => now()->addMinutes(5)]);
        $challenge = app(PendingKeyRecovery::class)->issue($owner, $fixture['public_key']);
        openssl_sign($challenge['signing_payload'], $signature, $fixture['synthetic_private_key'], OPENSSL_ALGO_SHA256);
        $proof = ['recovery_id' => $challenge['recovery_id'], 'signature' => base64_encode($signature)];
        $pair = ['pairing_token' => $fixture['token'], 'public_key' => $fixture['public_key'],
            'attestation_certificate_chain' => $fixture['certificates']];
        $this->assertSame(0, DB::table('device_key_states')->count());
        $this->child('winner', $recoveryFirst ? PendingKeyRecovery::PATH : '/api/device/v1/pair', $recoveryFirst ? $proof : $pair, true);
        $this->await(fn () => is_file($this->directory.'/locked'));
        $this->child('loser', $recoveryFirst ? '/api/device/v1/pair' : PendingKeyRecovery::PATH, $recoveryFirst ? $pair : $proof, false);
        $observer = $this->connection();
        $statement = $observer->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
        try {
            $this->await(function () use ($statement): bool {
                $statement->execute([$this->schema.'_loser']);

                return (int) $statement->fetchColumn() === 1;
            });
        } finally {
            file_put_contents($this->directory.'/release', '1');
        }
        foreach ($this->children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        $this->children = [];
        $winner = json_decode(file_get_contents($this->directory.'/winner.json'), true, flags: JSON_THROW_ON_ERROR);
        $loser = json_decode(file_get_contents($this->directory.'/loser.json'), true, flags: JSON_THROW_ON_ERROR);
        DB::purge('pgsql');
        if ($recoveryFirst) {
            $this->assertSame(200, $winner['status'], json_encode($winner));
            $this->assertSame('not_registered_closed', $winner['body']['outcome']);
            $this->assertSame(422, $loser['status'], json_encode($loser));
            $this->assertSame('PAIRING_KEY_CLOSED', $loser['body']['message']);
            $this->assertSame(0, DB::table('devices')->count());
            $this->assertNull($pairing->fresh()->consumed_at);
        } else {
            $this->assertSame(201, $winner['status'], json_encode($winner));
            $this->assertSame(200, $loser['status'], json_encode($loser));
            $this->assertSame('registered', $loser['body']['outcome']);
            $this->assertSame($winner['body']['device_id'], $loser['body']['registration']['device_id']);
            $this->assertTrue($loser['body']['registration']['can_activate']);
            $this->assertSame(1, DB::table('devices')->count());
            $this->assertNull(DB::table('device_key_states')->sole()->closed_at);
        }
    }
}
