<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\StoreSetup\Models\Currency;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

class SaveSupplier
{
    public function execute(array $data, int $actorId, ?int $id = null): Supplier
    {
        return DB::transaction(function () use ($data, $actorId, $id) {
            StoreSetting::lockCurrent();
            $supplier = $id ? Supplier::lockForUpdate()->findOrFail($id) : new Supplier;
            if ($id && $supplier->version !== (int) $data['version']) {
                throw new BusinessException('SUPPLIER_VERSION_CONFLICT', 'تغير سجل المورد. أعد تحميل البيانات قبل الحفظ.', 409);
            }
            if (Supplier::where('code', $data['code'])->when($id, fn ($q) => $q->whereKeyNot($id))->lockForUpdate()->first(['id'])) {
                throw new BusinessException('SUPPLIER_CODE_EXISTS', 'رمز المورد مستخدم بالفعل.');
            }
            if (! Currency::where('code', $data['currency'])->where('is_active', true)->sharedLock()->first()) {
                throw new BusinessException('CURRENCY_INACTIVE', 'عملة المورد غير نشطة.');
            }
            $before = $supplier->exists ? $supplier->toArray() : null;
            $bankChanged = array_key_exists('bank_info', $data) && $data['bank_info'] !== $supplier->bank_info;
            unset($data['version']);
            $supplier->fill([...$data, 'version' => ($supplier->version ?? 0) + 1, 'created_by' => $supplier->created_by ?? $actorId, 'updated_by' => $actorId])->save();
            app(RecordAudit::class)->execute($id ? 'purchasing.supplier_updated' : 'purchasing.supplier_created', 'supplier', $supplier->id, $before, [...$supplier->toArray(), 'bank_details_changed' => $bankChanged], $actorId);

            return $supplier->fresh();
        }, 3);
    }
}
