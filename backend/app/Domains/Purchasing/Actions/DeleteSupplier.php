<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a supplier that no document has ever used. A used supplier keeps its
 * history and is deactivated instead.
 */
class DeleteSupplier
{
    private const LABELS = [
        'purchase_orders' => 'أوامر شراء', 'goods_receipts' => 'استلام بضاعة', 'supplier_invoices' => 'فواتير موردين',
        'supplier_payments' => 'دفعات', 'supplier_credit_notes' => 'إشعارات دائنة', 'supplier_ledger_entries' => 'كشف حساب',
    ];

    public function execute(int $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId) {
            // Same exclusive lock as purchasing postings: nothing can start using the
            // supplier between the check and the delete.
            StoreSetting::lockForUpdate()->findOrFail(1);
            $supplier = Supplier::lockForUpdate()->findOrFail($id);
            $used = $this->usedBy($id);
            // Attachments are immutable records, so a supplier holding one is kept.
            if (DB::table('document_attachments')->where('entity_type', 'supplier')->where('entity_id', $id)->exists()) {
                $used[] = 'مرفقات';
            }
            if ($used) {
                throw new BusinessException('SUPPLIER_IN_USE', 'لا يمكن حذف مورد له سجلات ('.implode('، ', $used).'). أوقفه بدلاً من ذلك بإلغاء «نشط».', 409);
            }
            $before = $supplier->toArray();
            $supplier->delete();
            app(RecordAudit::class)->execute('purchasing.supplier_deleted', 'supplier', $id, $before, [], $actorId);
        }, 3);
    }

    /** Tables that reference this supplier, read from the schema so new modules are covered too. */
    private function usedBy(int $id): array
    {
        $tables = DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::raw('database()'))->where('referenced_table_name', 'suppliers')
            ->get(['table_name', 'column_name']);
        $used = [];
        foreach ($tables as $t) {
            $table = $t->table_name ?? $t->TABLE_NAME;
            $column = $t->column_name ?? $t->COLUMN_NAME;
            if (DB::table($table)->where($column, $id)->exists()) {
                $used[] = self::LABELS[$table] ?? 'سجلات أخرى';
            }
        }

        return array_values(array_unique($used));
    }
}
