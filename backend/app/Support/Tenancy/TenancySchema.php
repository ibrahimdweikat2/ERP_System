<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/** Read-only schema introspection shared by the tenancy migrations and erp:tenancy:verify. */
final class TenancySchema
{
    /** Business keys that are unique per company, not platform-wide. */
    public const COMPANY_UNIQUES = [
        'roles' => ['name'], 'document_sequences' => ['document_type'], 'fiscal_years' => ['name'],
        'accounts' => ['code'], 'journals' => ['code'], 'tax_codes' => ['code', 'effective_from'],
        'exchange_rates' => ['currency_code', 'rate_date'], 'categories' => ['slug'], 'units' => ['code'],
        'products' => ['sku'], 'product_barcodes' => ['barcode'], 'stock_locations' => ['code'],
        'serial_numbers' => ['serial_no'], 'suppliers' => ['code'], 'customers' => ['code'],
        'checks' => ['identity_hash'], 'operational_alerts' => ['dedup_key'], 'bank_statement_lines' => ['fingerprint'],
        'journal_entries' => ['entry_no'], 'stock_transfers' => ['document_no'], 'stock_adjustments' => ['document_no'],
        'stock_counts' => ['document_no'], 'purchase_orders' => ['document_no'], 'goods_receipts' => ['document_no'],
        'supplier_invoices' => ['document_no'], 'supplier_payments' => ['document_no'], 'supplier_credit_notes' => ['document_no'],
        'sales_invoices' => ['document_no'], 'customer_payments' => ['document_no'], 'sales_returns' => ['document_no'],
        'installment_contracts' => ['document_no'], 'check_deposit_batches' => ['document_no'], 'expenses' => ['document_no'],
        'cash_transfers' => ['document_no'], 'customer_settlements' => ['document_no'], 'warranty_claims' => ['document_no'],
    ];

    /** Tables whose primary key is a code or key, made (company_id, key). */
    public const KEYED = ['currencies' => 'code', 'approval_policies' => 'key', 'account_mappings' => 'key', 'business_policies' => 'key'];

    /** One row per company. */
    public const SINGLETONS = ['store_settings', 'purchasing_invoice_policies'];

    /** @return list<string> */
    public static function tables(): array
    {
        return DB::table('information_schema.tables')->where('table_schema', DB::raw('database()'))
            ->where('table_type', 'BASE TABLE')->orderBy('table_name')->get(['table_name'])
            ->map(fn ($r) => self::lower($r)['table_name'])->all();
    }

    /** Tables holding company data (company or nullable kind). */
    public static function companyTables(): array
    {
        return array_values(array_filter(self::tables(), fn ($t) => TenantTables::kind($t) !== TenantTables::GLOBAL));
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return DB::table('information_schema.columns')->where('table_schema', DB::raw('database()'))
            ->where('table_name', $table)->where('column_name', $column)->exists();
    }

    public static function column(string $table, string $column): ?array
    {
        $row = DB::table('information_schema.columns')->where('table_schema', DB::raw('database()'))
            ->where('table_name', $table)->where('column_name', $column)->first(['is_nullable', 'column_default', 'column_type']);

        return $row ? self::lower($row) : null;
    }

    /** @return array<string, list<string>> unique index name => ordered columns (PRIMARY included) */
    public static function uniqueIndexes(string $table): array
    {
        $indexes = [];
        foreach (DB::table('information_schema.statistics')->where('table_schema', DB::raw('database()'))
            ->where('table_name', $table)->where('non_unique', 0)->orderBy('index_name')->orderBy('seq_in_index')
            ->get(['index_name', 'column_name']) as $row) {
            $row = self::lower($row);
            $indexes[$row['index_name']][] = $row['column_name'];
        }

        return $indexes;
    }

    public static function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')->where('table_schema', DB::raw('database()'))
            ->where('table_name', $table)->where('index_name', $name)->exists();
    }

    public static function foreignKeyExists(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')->where('constraint_schema', DB::raw('database()'))
            ->where('table_name', $table)->where('constraint_name', $name)->where('constraint_type', 'FOREIGN KEY')->exists();
    }

    public static function checkExists(string $table, string $name): bool
    {
        return DB::table('information_schema.table_constraints')->where('constraint_schema', DB::raw('database()'))
            ->where('table_name', $table)->where('constraint_name', $name)->where('constraint_type', 'CHECK')->exists();
    }

    /**
     * Foreign keys pointing at the given table, one entry per constraint.
     *
     * @return list<array{name: string, table: string, columns: list<string>, referenced: list<string>, delete: string, update: string}>
     */
    public static function referencing(string $table): array
    {
        $rules = [];
        foreach (DB::table('information_schema.referential_constraints')->where('constraint_schema', DB::raw('database()'))
            ->where('referenced_table_name', $table)->get(['constraint_name', 'delete_rule', 'update_rule']) as $row) {
            $row = self::lower($row);
            $rules[$row['constraint_name']] = $row;
        }
        $keys = [];
        foreach (DB::table('information_schema.key_column_usage')->where('table_schema', DB::raw('database()'))
            ->where('referenced_table_name', $table)->orderBy('constraint_name')->orderBy('ordinal_position')
            ->get(['constraint_name', 'table_name', 'column_name', 'referenced_column_name']) as $row) {
            $row = self::lower($row);
            $keys[$row['constraint_name']] ??= ['name' => $row['constraint_name'], 'table' => $row['table_name'], 'columns' => [], 'referenced' => [],
                'delete' => $rules[$row['constraint_name']]['delete_rule'] ?? 'RESTRICT', 'update' => $rules[$row['constraint_name']]['update_rule'] ?? 'RESTRICT'];
            $keys[$row['constraint_name']]['columns'][] = $row['column_name'];
            $keys[$row['constraint_name']]['referenced'][] = $row['referenced_column_name'];
        }

        return array_values($keys);
    }

    /** MySQL identifiers are limited to 64 characters; long names keep a stable hash suffix. */
    public static function name(string $name): string
    {
        return strlen($name) <= 64 ? $name : substr($name, 0, 55).'_'.substr(md5($name), 0, 8);
    }

    private static function lower(object|array $row): array
    {
        return array_change_key_case((array) $row, CASE_LOWER);
    }
}
