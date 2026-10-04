<?php

namespace App\Domains\StoreSetup\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\RecordUsage;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a list/settings record (category, brand, account, cashbox, …) that nothing uses.
 * Used records are kept and can be deactivated instead; records the code itself depends on
 * (the base currency, the seeded chart of accounts, system journals and locations) are kept.
 */
class DeleteMasterRecord
{
    /** Seeded by AccountingTemplateSeeder and looked up by code when posting. */
    private const SYSTEM_JOURNALS = ['general', 'sales', 'purchases', 'receipts', 'payments', 'bank', 'inventory', 'checks', 'opening'];

    /** Seeded by CatalogMasterSeeder; stock flows look these locations and the unit up. */
    private const SYSTEM_LOCATIONS = ['SHOWROOM', 'WAREHOUSE', 'RETURNS', 'DAMAGED', 'WARRANTY', 'RESERVED'];

    private const SYSTEM_UNITS = ['piece'];

    /** The seeded chart of accounts; account mappings and reports rely on these codes. */
    private const SYSTEM_ACCOUNTS = ['1100', '1110', '1120', '1130', '1200', '1300', '1400', '1500', '1600', '2100', '2200', '2210', '2300',
        '3100', '3200', '3300', '3400', '4100', '4200', '4300', '5100', '5200', '6100', '6200', '6300', '6400', '6500', '6600'];

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    public function execute(string $kind, string $model, int|string $id, int $actorId): void
    {
        DB::transaction(function () use ($kind, $model, $id, $actorId) {
            StoreSetting::lockCurrent();
            $table = (new $model)->getTable();
            $key = (new $model)->getKeyName();
            $row = DB::table($table)->where($key, $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $system = match ($kind) {
                'exchange-rates' => 'أسعار الصرف محفوظة للتدقيق ولا تُحذف؛ أضف سعراً بتاريخ جديد.',
                'currencies' => ($row->is_base ?? false) ? 'العملة الأساسية للمتجر لا تُحذف.' : null,
                'accounts' => in_array($row->code, self::SYSTEM_ACCOUNTS, true) ? 'هذا الحساب من دليل الحسابات الأساسي الذي يعتمد عليه النظام؛ أوقفه بدل حذفه.' : null,
                'journals' => in_array($row->code, self::SYSTEM_JOURNALS, true) ? 'هذا الدفتر يستخدمه النظام عند الترحيل ولا يُحذف.' : null,
                'stock-locations' => in_array($row->code, self::SYSTEM_LOCATIONS, true) ? 'هذا الموقع من مواقع النظام الأساسية ولا يُحذف.' : null,
                'units' => in_array($row->code, self::SYSTEM_UNITS, true) ? 'وحدة «قطعة» أساسية في النظام ولا تُحذف.' : null,
                default => null,
            };
            if ($system) {
                throw new BusinessException('SYSTEM_RECORD', $system, 409);
            }
            if ($used = RecordUsage::of($table, $row)) {
                throw new BusinessException('RECORD_IN_USE', 'لا يمكن الحذف لأنه مستخدم في: '.implode('، ', $used).'. أوقفه بدلاً من ذلك.', 409);
            }
            DB::table($table)->where($key, $id)->delete();
            app(RecordAudit::class)->execute('masters.deleted', $kind, $id, (array) $row, [], $actorId);
        }, 3);
    }
}
