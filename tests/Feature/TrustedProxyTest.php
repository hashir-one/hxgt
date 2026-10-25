<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_local_nginx_can_forward_https_and_the_client_ip(): void
    {
        Route::get('/_test/proxy', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(), 'ip' => $request->ip(), 'url' => url('/login'),
        ]));

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '203.0.113.8', 'X-Forwarded-Host' => 'attacker.example'])
            ->getJson('http://pay.hxdev.in/_test/proxy')->assertOk()
            ->assertJson(['secure' => true, 'ip' => '203.0.113.8', 'url' => 'https://pay.hxdev.in/login']);
    }

    public function test_direct_clients_cannot_spoof_https_or_their_ip(): void
    {
        Route::get('/_test/proxy', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(), 'ip' => $request->ip(), 'url' => url('/login'),
        ]));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.8'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '127.0.0.1'])
            ->getJson('http://pay.hxdev.in/_test/proxy')->assertOk()
            ->assertJson(['secure' => false, 'ip' => '198.51.100.8', 'url' => 'http://pay.hxdev.in/login']);
    }
}
