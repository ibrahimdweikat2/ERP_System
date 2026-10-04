<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform routes: only the superadmin may manage companies. */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformAdmin() ?? false, 403, 'هذه الصفحة خاصة بإدارة المنصة.');

        return $next($request);
    }
}
