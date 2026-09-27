<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Accounting\Actions\SaveAccountingMaster;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\StoreSetup\Actions\SaveStoreSettings;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccountingMasterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingSetupController extends Controller
{
    public function settings(): JsonResponse
    {
        return response()->json(['data' => StoreSetting::findOrFail(1)]);
    }

    public function saveSettings(Request $r, SaveStoreSettings $action): JsonResponse
    {
        $data = $r->validate(['trade_name' => ['required', 'string', 'max:160'], 'platform_name' => ['nullable', 'string', 'max:60'], 'legal_name' => ['nullable', 'string', 'max:160'], 'owner_name' => ['nullable', 'string', 'max:120'], 'address' => ['nullable', 'string', 'max:500'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:255'], 'tax_number' => ['nullable', 'required_if:vat_registered,true', 'string', 'max:80'], 'vat_registered' => ['required', 'boolean'], 'base_currency' => ['required', Rule::exists('currencies', 'code')->where('is_active', true)], 'timezone' => ['required', 'timezone'], 'locale' => ['required', Rule::in(['ar', 'en'])], 'price_display' => ['required', Rule::in(['inclusive', 'exclusive'])], 'invoice_footer' => ['nullable', 'string', 'max:3000']]);

        return response()->json(['data' => $action->execute($data)]);
    }

    public function index(Request $r): JsonResponse
    {
        $kind = $r->route('master');
        $class = SaveAccountingMaster::MODELS[$kind];
        $model = new $class;
        $data = $r->validate(['per_page' => ['nullable', 'integer', 'between:1,100'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = $class::query();
        $searchColumn = match ($kind) {
            'accounts','journals','tax-codes' => 'name_ar','exchange-rates' => 'currency_code',default => 'name'
        };
        if (! empty($data['search'])) {
            $query->where($searchColumn, 'like', '%'.$data['search'].'%');
        }
        $query->orderBy($model->getKeyName());

        return response()->json($query->paginate(\App\Support\PerPage::resolve(50)));
    }

    public function store(AccountingMasterRequest $r, SaveAccountingMaster $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($r->route('master'), $r->validated())], 201);
    }

    public function update(AccountingMasterRequest $r, SaveAccountingMaster $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($r->route('master'), $r->validated(), $r->route('id'))]);
    }

    public function destroy(Request $r, \App\Domains\StoreSetup\Actions\DeleteMasterRecord $action): JsonResponse
    {
        $kind = $r->route('master');
        $action->execute($kind, SaveAccountingMaster::MODELS[$kind], $r->route('id'), $r->user()->id);

        return response()->json(['message' => 'تم الحذف.']);
    }

    public function fiscalYears(): JsonResponse
    {
        return response()->json(FiscalYear::with('periods')->orderByDesc('starts_on')->paginate(\App\Support\PerPage::resolve(20)));
    }

    public function createYear(Request $r, CreateFiscalYear $action): JsonResponse
    {
        $data = $r->validate(['name' => ['required', 'string', 'max:80', 'unique:fiscal_years'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']]);

        return response()->json(['data' => $action->execute($data)], 201);
    }

    public function periods(): JsonResponse
    {
        return response()->json(AccountingPeriod::orderByDesc('starts_on')->paginate(\App\Support\PerPage::resolve(60)));
    }
}
