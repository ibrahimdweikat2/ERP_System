<?php

namespace App\Http\Middleware;

use App\Domains\Platform\Models\Company;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects disabled accounts and suspended companies, then sets the company
 * context for the rest of the request: the user's company, or the platform
 * for a superadmin. Runs before route-model binding (see bootstrap/app.php).
 */
class RequireActiveUser
{
    public function __construct(private CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->status !== 'active') {
            $this->signOut($request, 'الحساب غير نشط. راجع مدير المتجر.');
        }
        $previous = $this->context->snapshot();
        if ($user->isPlatformAdmin()) {
            $this->context->setPlatform();
            $user->setRelation('roles', collect());
        } else {
            $company = $user->company_id ? Company::find($user->company_id) : null;
            if (! $company?->isActive()) {
                $this->signOut($request, 'حساب الشركة موقوف. راجع إدارة المنصة.');
            }
            $this->context->setCompany($company->id);
            $user->setRelation('company', $company);
            $user->load('roles.permissions');
            $store = StoreSetting::query()->first();
            if ($store) {
                config(['app.timezone' => $store->timezone]);
                date_default_timezone_set($store->timezone);
            }
        }
        try {
            return $next($request);
        } finally {
            // Tests issue several requests from one process; leave the caller's context as it was.
            $this->context->reinstate($previous);
        }
    }

    private function signOut(Request $request, string $message): never
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        abort(401, $message);
    }
}
