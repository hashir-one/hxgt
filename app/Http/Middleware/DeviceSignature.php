<?php

namespace App\Http\Middleware;

use App\Domain\Devices\DeviceRequestVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DeviceSignature
{
    public function __construct(private readonly DeviceRequestVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('device', $this->verifier->verify($request));

        return $next($request);
    }
}
