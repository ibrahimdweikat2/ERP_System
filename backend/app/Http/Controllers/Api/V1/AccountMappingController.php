<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Accounting\Models\Account;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountMappingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('account_mappings')->join('accounts', 'accounts.id', '=', 'account_mappings.account_id')->select('account_mappings.key', 'account_mappings.account_id', 'accounts.code', 'accounts.name_ar', 'accounts.account_type')->orderBy('key')->get()]);
    }

    public function update(Request $r, string $key): JsonResponse
    {
        $d = $r->validate(['account_id' => ['required', 'integer', 'exists:accounts,id'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return DB::transaction(function () use ($key, $d) {
            StoreSetting::lockCurrent();
            $mapping = DB::table('account_mappings')->where('key', $key)->lockForUpdate()->first();
            abort_unless($mapping, 404);
            $old = Account::findOrFail($mapping->account_id);
            $new = Account::lockForUpdate()->findOrFail($d['account_id']);
            if (! $new->active || $old->account_type !== $new->account_type || $old->is_control_account !== $new->is_control_account) {
                throw new BusinessException('ACCOUNT_MAPPING_INVALID', 'اختر حساباً نشطاً من نفس النوع والطبيعة الرقابية.');
            }
            if ($old->id !== $new->id && DB::table('journal_lines')->where('account_id', $old->id)->exists()) {
                throw new BusinessException('MAPPING_IN_USE', 'تغيير رابط حساب مستخدم يتطلب تسوية مالية منفصلة.');
            }
            DB::table('account_mappings')->where('key', $key)->update(['account_id' => $new->id]);
            app(RecordAudit::class)->execute('accounting.mapping_changed', 'account_mapping', $key, ['account_id' => $old->id], $d);

            return response()->json(['data' => ['key' => $key, 'account_id' => $new->id]]);
        }, 3);
    }
}
