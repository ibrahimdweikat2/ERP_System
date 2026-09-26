<?php

namespace Database\Seeders;

use App\Domains\StoreSetup\Models\DocumentSequence;
use Illuminate\Database\Seeder;

class SequenceSeeder extends Seeder
{
    public function run(): void
    {
        $prefixes = ['sales_invoice' => 'SI/', 'sales_credit_note' => 'SC/', 'customer_receipt' => 'RC/', 'payment_voucher' => 'PV/', 'purchase_order' => 'PO/', 'goods_receipt' => 'GR/', 'supplier_invoice' => 'PI/', 'supplier_return' => 'PR/', 'journal_entry' => 'JE/', 'installment_contract' => 'IC/', 'check_deposit' => 'CD/', 'stock_adjustment' => 'SA/', 'stock_transfer' => 'ST/', 'stock_count' => 'CT/', 'warranty_claim' => 'WC/', 'expense' => 'EX/'];
        foreach ($prefixes as $type => $prefix) {
            DocumentSequence::firstOrCreate(['document_type' => $type], ['prefix' => $prefix, 'year_pattern' => 'Y', 'next_number' => 1, 'padding' => 6, 'reset_policy' => 'yearly']);
        }
    }
}
