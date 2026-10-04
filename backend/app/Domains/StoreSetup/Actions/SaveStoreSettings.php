<?php

namespace App\Domains\StoreSetup\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SaveStoreSettings
{
    public function execute(array $data): StoreSetting
    {
        return DB::transaction(function () use ($data) {
            $store = StoreSetting::lockCurrent();
            if ($store->base_currency !== $data['base_currency']) {
                if ((Schema::hasTable('journal_entries') && DB::table('journal_entries')->exists()) || DB::table('exchange_rates')->exists() || (Schema::hasTable('products') && DB::table('products')->exists())) {
                    throw new BusinessException('BASE_CURRENCY_IN_USE', 'لا يمكن تغيير العملة الأساسية بعد تسجيل أسعار المنتجات أو الصرف أو القيود.');
                }
                DB::table('currencies')->update(['is_base' => false]);
                DB::table('currencies')->where('code', $data['base_currency'])->update(['is_base' => true]);
            }
            $before = $store->toArray();
            $store->update($data);
            app(RecordAudit::class)->execute('store.settings_updated', 'store_setting', $store->id, $before, $store->toArray());

            return $store;
        }, 3);
    }
}
