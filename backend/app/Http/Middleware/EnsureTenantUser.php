<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Company routes: the platform superadmin has no company and cannot use them. */
class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->isPlatformAdmin() ?? true, 403, 'هذه الصفحة خاصة بمستخدمي الشركات.');

        return $next($request);
    }
}
