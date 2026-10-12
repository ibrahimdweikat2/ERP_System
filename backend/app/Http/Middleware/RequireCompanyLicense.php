<?php

namespace App\Http\Middleware;

use App\Domains\Licensing\CompanyLicensing;
use App\Support\BusinessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company routes beyond the dashboard need a valid company license. Permission checks
 * already fail without one (User::hasPermission); this also covers routes that check
 * no permission, such as notifications and report exports.
 */
class RequireCompanyLicense
{
    public function __construct(private CompanyLicensing $licensing) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->licensing->isValid($request->user()?->company_id)) {
            throw new BusinessException('LICENSE_REQUIRED', 'لا يوجد ترخيص فعّال لهذه الشركة. يستطيع مالك الشركة إضافة الترخيص من الإدارة ← ترخيص الشركة.', 403);
        }

        return $next($request);
    }
}
