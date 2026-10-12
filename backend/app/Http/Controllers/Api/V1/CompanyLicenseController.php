<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Licensing\CompanyLicensing;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in company's license. Owner only, and reachable without a valid license:
 * it is how the owner adds one.
 */
class CompanyLicenseController extends Controller
{
    public function __construct(private CompanyLicensing $licensing) {}

    public function show(Request $r): JsonResponse
    {
        $companyId = $this->owner($r);
        // Opening the page performs a due online check, so the owner never waits on the schedule.
        if ($this->licensing->isDue($companyId)) {
            $this->licensing->verify($companyId);
        }

        return response()->json(['data' => $this->licensing->status($companyId)]);
    }

    public function store(Request $r): JsonResponse
    {
        $this->owner($r);
        $data = $r->validate(['license_key' => ['required', 'string', 'min:4', 'max:64']]);
        $this->licensing->activate($r->user()->company, $data['license_key'], $r->user());

        return response()->json(['data' => $this->licensing->status($r->user()->company_id), 'message' => 'تم تفعيل الترخيص.']);
    }

    public function verify(Request $r): JsonResponse
    {
        $companyId = $this->owner($r);
        $this->licensing->verify($companyId);

        return response()->json(['data' => $this->licensing->status($companyId)]);
    }

    public function destroy(Request $r): JsonResponse
    {
        $companyId = $this->owner($r);
        $this->licensing->deactivate($companyId, $r->user());

        return response()->json(['data' => $this->licensing->status($companyId), 'message' => 'تم إلغاء تفعيل الترخيص.']);
    }

    private function owner(Request $r): int
    {
        abort_unless($r->user()->isOwner(), 403, 'إدارة ترخيص الشركة متاحة لمالك الشركة فقط.');

        return $r->user()->company_id;
    }
}
