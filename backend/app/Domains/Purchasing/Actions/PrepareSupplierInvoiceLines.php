<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Purchasing\Models\GoodsReceipt;
use App\Domains\Purchasing\Models\GoodsReceiptLine;
use App\Domains\Tax\Actions\CalculateDocumentTax;
use App\Domains\Tax\Models\TaxCode;
use App\Support\BusinessException;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class PrepareSupplierInvoiceLines
{
    public function execute(array $rawLines, int $supplierId, string $currency, string $postingDate, string $invoiceDate, string $rate, bool $savedTax = false): array
    {
        $lineIds = array_column($rawLines, 'goods_receipt_line_id');
        $receiptIds = GoodsReceiptLine::whereIn('id', $lineIds)->pluck('goods_receipt_id')->unique()->sort()->values();
        // A receipt header serializes every invoice allocation of its quantities.
        $receipts = GoodsReceipt::whereIn('id', $receiptIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $receiptLines = GoodsReceiptLine::whereIn('id', $lineIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($receipts as $receipt) {
            if ($receipt->status !== 'posted' || $receipt->supplier_id !== $supplierId || $receipt->currency !== $currency || $receipt->document_date > $postingDate) {
                throw new BusinessException('INVOICE_RECEIPT_MISMATCH', 'اختر استلاماً مرحّلاً لنفس المورد والعملة، بتاريخه أو بعده.');
            }
        }
        $used = [];
        $matched = DB::table('supplier_invoice_lines as l')->join('supplier_invoices as i', 'i.id', '=', 'l.supplier_invoice_id')->whereIn('l.goods_receipt_line_id', $lineIds)->where('i.status', 'posted')->orderBy('l.id')->lockForUpdate()->get(['l.goods_receipt_line_id', 'l.quantity', 'l.receipt_foreign_value', 'l.receipt_base_value']);
        foreach ($matched as $line) {
            $key = $line->goods_receipt_line_id;
            foreach (['quantity', 'receipt_foreign_value', 'receipt_base_value'] as $field) {
                $used[$key][$field] = Decimal::add($used[$key][$field] ?? '0', $line->$field);
            }
        }
        $totals = array_fill_keys(['net_total', 'tax_total', 'foreign_total', 'base_net_total', 'base_tax_total', 'base_total', 'grni_total', 'price_variance_total', 'fx_variance_total'], '0.0000');
        $lines = [];
        foreach ($rawLines as $raw) {
            $receiptLine = $receiptLines->get($raw['goods_receipt_line_id']);
            if (! $receiptLine || ! $receipts->has($receiptLine->goods_receipt_id)) {
                throw new BusinessException('INVOICE_RECEIPT_LINE_INVALID', 'بند الاستلام غير موجود.');
            }
            $quantity = Decimal::money($raw['quantity']);
            $precision = DB::table('products as p')->join('units as u', 'u.id', '=', 'p.unit_id')->where('p.id', $receiptLine->product_id)->value('u.decimal_places');
            if (Decimal::cmp($quantity, '0') <= 0 || Decimal::of($quantity)->stripTrailingZeros()->getScale() > $precision) {
                throw new BusinessException('INVALID_INVOICE_QUANTITY', 'راجع كمية الفاتورة ودقة وحدة القياس.');
            }
            $allocated = $used[$receiptLine->id] ?? array_fill_keys(['quantity', 'receipt_foreign_value', 'receipt_base_value'], '0');
            $nextQuantity = Decimal::add($allocated['quantity'], $quantity);
            if (Decimal::cmp($nextQuantity, $receiptLine->quantity) > 0) {
                throw new BusinessException('RECEIPT_OVER_INVOICED', 'كمية الفاتورة تتجاوز الكمية المستلمة غير المفوترة.');
            }
            $matchedValues = [];
            foreach (['receipt_foreign_value' => 'foreign_value', 'receipt_base_value' => 'base_value'] as $field => $source) {
                $cumulative = Decimal::cmp($nextQuantity, $receiptLine->quantity) === 0 ? $receiptLine->$source : (string) Decimal::of($receiptLine->$source)->multipliedBy($nextQuantity)->dividedBy($receiptLine->quantity, 4, RoundingMode::HALF_UP);
                $matchedValues[$field] = Decimal::sub($cumulative, $allocated[$field]);
                $used[$receiptLine->id][$field] = $cumulative;
            }
            $used[$receiptLine->id]['quantity'] = $nextQuantity;
            if ($savedTax) {
                $tax = array_intersect_key($raw, array_flip(['tax_code_id', 'tax_snapshot', 'tax_rate', 'tax_inclusive', 'taxable_base', 'tax_amount', 'total']));
            } else {
                $tax = app(CalculateDocumentTax::class)->execute($raw, $invoiceDate);
                $taxCode = TaxCode::sharedLock()->findOrFail($raw['tax_code_id']);
                $tax['tax_snapshot'] = [...$tax['tax_snapshot'], 'input_account_id' => $taxCode->input_account_id, 'output_account_id' => $taxCode->output_account_id];
            }
            $baseNet = Decimal::mul($tax['taxable_base'], $rate);
            $baseTax = Decimal::mul($tax['tax_amount'], $rate);
            $expectedAtCurrentRate = Decimal::mul($matchedValues['receipt_foreign_value'], $rate);
            $line = ['goods_receipt_line_id' => $receiptLine->id, 'product_id' => $receiptLine->product_id, 'product_snapshot' => $receiptLine->product_snapshot, 'quantity' => $quantity, 'unit_price' => Decimal::money($raw['unit_price']), 'discount_amount' => Decimal::money($raw['discount_amount']), ...$tax, 'tax_recoverable' => (bool) $raw['tax_recoverable'], 'tax_account_id' => $raw['tax_recoverable'] ? $tax['tax_snapshot']['input_account_id'] : null, 'base_net' => $baseNet, 'base_tax' => $baseTax, 'base_total' => Decimal::add($baseNet, $baseTax), ...$matchedValues, 'price_variance' => Decimal::sub($baseNet, $expectedAtCurrentRate), 'fx_variance' => Decimal::sub($expectedAtCurrentRate, $matchedValues['receipt_base_value'])];
            foreach (['net_total' => 'taxable_base', 'tax_total' => 'tax_amount', 'foreign_total' => 'total', 'base_net_total' => 'base_net', 'base_tax_total' => 'base_tax', 'base_total' => 'base_total', 'grni_total' => 'receipt_base_value', 'price_variance_total' => 'price_variance', 'fx_variance_total' => 'fx_variance'] as $total => $field) {
                $totals[$total] = Decimal::add($totals[$total], $line[$field]);
            }
            $lines[] = $line;
            $lines[array_key_last($lines)]['receipt_exchange_rate'] = $receipts[$receiptLine->goods_receipt_id]->exchange_rate;
        }
        foreach ($totals as $value) {
            if (Decimal::of($value)->abs()->isGreaterThan('99999999999999.9999')) {
                throw new BusinessException('AMOUNT_OUT_OF_RANGE','إجمالي الفاتورة يتجاوز الدقة المدعومة.');
            }
        }

        return ['lines' => $lines, ...$totals];
    }
}
