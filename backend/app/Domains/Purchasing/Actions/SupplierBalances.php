<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class SupplierBalances
{
    public function invoice(SupplierInvoice $invoice): array
    {
        $paid = DB::table('supplier_payment_allocations')->where('supplier_invoice_id', $invoice->id)->lockForUpdate()->get();
        $credits = DB::table('supplier_credit_notes')->where('supplier_invoice_id', $invoice->id)->where('status', 'posted')->lockForUpdate()->get();
        $amount = '0';
        $base = '0';
        foreach ($paid->concat($credits) as $row) {
            $amount = Decimal::add($amount, $row->amount);
            $base = Decimal::add($base, $row->base_amount);
        }

        return ['settled' => $amount, 'settled_base' => $base, 'remaining' => Decimal::sub($invoice->foreign_total, $amount), 'remaining_base' => Decimal::sub($invoice->base_total, $base)];
    }
}
