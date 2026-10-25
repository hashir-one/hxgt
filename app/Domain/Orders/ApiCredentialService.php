<?php

namespace App\Domain\Orders;

use App\Models\Merchant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ApiCredentialService
{
    public function issue(Merchant $merchant, string $name, string $type, array $scopes): array
    {
        $prefix = Str::lower(Str::random(12));
        $secret = Str::random(48);
        $credential = $merchant->apiCredentials()->create([
            'name' => $name,
            'type' => $type,
            'key_prefix' => $prefix,
            'secret_hash' => Hash::make($secret),
            'signing_secret_encrypted' => $secret,
            'scopes' => array_values(array_unique($scopes)),
        ]);

        return [$credential, "upi_live_{$prefix}_{$secret}"];
    }
}
