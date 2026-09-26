<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        abort_unless(collect($permissions)->contains(fn (string $permission) => $request->user()?->hasPermission($permission)), 403, 'لا تملك صلاحية تنفيذ هذه العملية.');

        return $next($request);
    }
}
