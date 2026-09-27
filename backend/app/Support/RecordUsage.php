<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Finds which tables still reference a row, read from the schema's foreign keys so new
 * modules are covered automatically. Used before any delete: a referenced row is kept.
 */
final class RecordUsage
{
    private const LABELS = [
        'products' => 'منتجات', 'product_barcodes' => 'باركودات', 'customers' => 'عملاء', 'suppliers' => 'موردين',
        'sales_invoices' => 'فواتير بيع', 'sales_invoice_lines' => 'سطور فواتير بيع', 'sales_returns' => 'مرتجعات', 'sales_return_lines' => 'سطور مرتجعات',
        'purchase_orders' => 'أوامر شراء', 'purchase_order_lines' => 'سطور أوامر شراء', 'goods_receipts' => 'استلام بضاعة', 'goods_receipt_lines' => 'سطور استلام',
        'supplier_invoices' => 'فواتير موردين', 'supplier_invoice_lines' => 'سطور فواتير موردين', 'supplier_payments' => 'دفعات موردين',
        'supplier_payment_allocations' => 'توزيع دفعات موردين', 'supplier_credit_notes' => 'إشعارات دائنة', 'supplier_credit_note_lines' => 'سطور إشعارات دائنة',
        'customer_payments' => 'سندات قبض', 'payment_allocations' => 'توزيع سندات قبض', 'customer_settlements' => 'تسويات عملاء', 'checks' => 'شيكات',
        'installment_contracts' => 'عقود تقسيط', 'expenses' => 'مصروفات', 'cash_transfers' => 'تحويلات نقدية', 'cashier_sessions' => 'جلسات صندوق',
        'journal_entries' => 'قيود', 'journal_lines' => 'سطور قيود', 'accounts' => 'حسابات فرعية', 'account_mappings' => 'ربط الحسابات',
        'tax_codes' => 'رموز ضريبة', 'tax_transactions' => 'حركات ضريبة', 'cashboxes' => 'صناديق', 'bank_accounts' => 'حسابات بنكية',
        'exchange_rates' => 'أسعار صرف', 'store_settings' => 'إعدادات المتجر', 'categories' => 'تصنيفات فرعية',
        'inventory_movements' => 'حركات مخزون', 'inventory_balances' => 'أرصدة مخزون', 'serial_numbers' => 'أرقام تسلسلية',
        'stock_adjustments' => 'تسويات مخزون', 'stock_adjustment_lines' => 'سطور تسويات', 'stock_transfers' => 'تحويلات مخزون',
        'stock_transfer_lines' => 'سطور تحويلات', 'stock_counts' => 'جرد', 'stock_count_lines' => 'سطور جرد',
        'customer_ledger_entries' => 'كشف حساب عملاء', 'supplier_ledger_entries' => 'كشف حساب موردين', 'treasury_movements' => 'حركات خزينة',
        'warranty_claims' => 'مطالبات ضمان', 'bank_statement_lines' => 'كشوف بنك',
    ];

    /**
     * @param  array<string,mixed>|object  $row  the row about to be deleted
     * @param  list<string>  $ignore  tables deleted together with the row (its own lines)
     * @return list<string> Arabic names of the tables that still reference it
     */
    public static function of(string $table, array|object $row, array $ignore = []): array
    {
        $row = (array) $row;
        $keys = DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::raw('database()'))->where('referenced_table_name', $table)
            ->get(['table_name', 'column_name', 'referenced_column_name']);
        $used = [];
        foreach ($keys as $key) {
            $key = array_change_key_case((array) $key, CASE_LOWER);
            $value = $row[$key['referenced_column_name']] ?? null;
            if ($value === null || in_array($key['table_name'], $ignore, true)) {
                continue;
            }
            if (DB::table($key['table_name'])->where($key['column_name'], $value)->exists()) {
                $used[] = self::LABELS[$key['table_name']] ?? 'سجلات أخرى';
            }
        }

        return array_values(array_unique($used));
    }
}
