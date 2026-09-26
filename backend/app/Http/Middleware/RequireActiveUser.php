<?php

namespace App\Http\Middleware;

use App\Domains\StoreSetup\Models\StoreSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== 'active') {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            abort(401, 'الحساب غير نشط. راجع مدير المتجر.');
        }
        $request->user()->load('roles.permissions');
        $store = StoreSetting::find(1);
        if ($store) {
            config(['app.timezone' => $store->timezone]);
            date_default_timezone_set($store->timezone);
        }

        return $next($request);
    }
}
