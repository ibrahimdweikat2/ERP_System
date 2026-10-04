<?php

namespace App\Domains\Customers\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Customers\Models\Customer;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a customer that no document has ever used. A used customer keeps its
 * history and is deactivated instead.
 */
class DeleteCustomer
{
    /** Owned by the customer itself and removed with it. */
    private const OWNED = ['customer_followups'];

    private const LABELS = [
        'sales_invoices' => 'فواتير بيع', 'sales_returns' => 'مرتجعات', 'customer_payments' => 'سندات قبض',
        'customer_ledger_entries' => 'كشف حساب', 'installment_contracts' => 'عقود تقسيط', 'checks' => 'شيكات',
        'customer_settlements' => 'تسويات', 'warranty_claims' => 'مطالبات ضمان',
    ];

    public function execute(int $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId) {
            // Same exclusive lock as sales and receipts: nothing can start using the
            // customer between the check and the delete.
            StoreSetting::lockCurrent();
            $customer = Customer::lockForUpdate()->findOrFail($id);
            $used = $this->usedBy($id);
            // Attachments are immutable records, so a customer holding one is kept.
            if (DB::table('document_attachments')->where('entity_type', 'customer')->where('entity_id', $id)->exists()) {
                $used[] = 'مرفقات';
            }
            if ($used) {
                throw new BusinessException('CUSTOMER_IN_USE', 'لا يمكن حذف عميل له سجلات ('.implode('، ', $used).'). أوقفه بدلاً من ذلك بإلغاء «نشط».', 409);
            }
            $before = $customer->toArray();
            DB::table('customer_followups')->where('customer_id', $id)->delete();
            $customer->delete();
            app(RecordAudit::class)->execute('customers.deleted', 'customer', $id, $before, [], $actorId);
        }, 3);
    }

    /** Tables that reference this customer, read from the schema so new modules are covered too. */
    private function usedBy(int $id): array
    {
        $tables = DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::raw('database()'))->where('referenced_table_name', 'customers')
            ->whereNotIn('table_name', self::OWNED)->get(['table_name', 'column_name']);
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
