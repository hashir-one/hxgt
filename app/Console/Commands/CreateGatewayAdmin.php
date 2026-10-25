<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateGatewayAdmin extends Command
{
    protected $signature = 'gateway:create-admin {email} {--name=Platform Administrator}';

    protected $description = 'Provision a new operator account; email OTP and TOTP enrollment are required on login';

    public function handle(): int
    {
        $data = ['email' => strtolower(trim($this->argument('email'))), 'name' => $this->option('name')];
        $validator = Validator::make($data, ['email' => ['required', 'email', 'max:255', 'unique:users,email'], 'name' => ['required', 'string', 'max:120']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        DB::transaction(function () use ($data): void {
            $user = User::create($data + ['role' => 'admin', 'password' => Str::random(64)]);
            app(AuditLogger::class)->record('admin.provisioned', null, User::class, $user->id, 'Provisioned by the server operator via CLI.');
        });
        $this->info('Administrator created. Sign in with email OTP and enroll an authenticator.');

        return self::SUCCESS;
    }
}
