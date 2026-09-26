<?php

namespace App\Domains\StoreSetup\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $fillable = ['document_type', 'prefix', 'year_pattern', 'next_number', 'padding', 'reset_policy'];

    protected function casts(): array
    {
        return ['next_number' => 'integer', 'padding' => 'integer'];
    }

    public const TYPES = ['sales_invoice', 'sales_credit_note', 'customer_receipt', 'payment_voucher', 'purchase_order', 'goods_receipt', 'supplier_invoice', 'supplier_return', 'journal_entry', 'installment_contract', 'check_deposit', 'stock_adjustment', 'stock_transfer', 'stock_count', 'warranty_claim', 'expense'];
}
