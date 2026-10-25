<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecondFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_if($user?->disabled_at !== null, 403, 'Account disabled');
        if ($user && ($user->isAdmin() || $user->totp_enabled_at)) {
            if ($request->session()->get('mfa_user_id') !== $user->id || $request->session()->get('mfa_at', 0) < now()->subHours(8)->timestamp) {
                return redirect()->route('security.totp');
            }
        }
        if ($user?->isAdmin() && app()->environment('production')) {
            abort_unless(in_array($request->ip(), config('gateway.security.admin_ips', []), true), 403, 'Admin network not allowed');
        }

        return $next($request);
    }
}
