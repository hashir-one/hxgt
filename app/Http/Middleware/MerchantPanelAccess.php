<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantPanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->merchant !== null && in_array($request->user()->role, ['owner', 'staff'], true), 403);
        if (! $request->isMethod('get') && ! $request->isMethod('head')) {
            abort_unless($request->user()->role === 'owner' && $request->user()->merchant->status === 'active', 403);
        }

        return $next($request);
    }
}
