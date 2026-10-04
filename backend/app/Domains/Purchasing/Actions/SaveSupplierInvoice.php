<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Purchasing\Models\PurchasingInvoicePolicy;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

class SaveSupplierInvoice
{
    public static function normalizeNumber(string $number): string
    {
        return mb_strtoupper(preg_replace('/\\s+/u', ' ', trim($number)));
    }

    public function execute(array $data, int $actorId, ?int $id = null): SupplierInvoice
    {
        return DB::transaction(function () use ($data, $actorId, $id) {
            $store = StoreSetting::sharedCurrent();
            $policy = PurchasingInvoicePolicy::sharedCurrent();
            $doc = $id ? SupplierInvoice::with('lines')->lockForUpdate()->findOrFail($id) : new SupplierInvoice;
            if ($id && ($doc->status !== 'draft' || $doc->version !== (int) $data['version'])) {
                throw new BusinessException('INVOICE_NOT_EDITABLE', 'الفاتورة مرحّلة أو تغيرت نسختها.', 409);
            }
            // Serialize duplicate supplier invoice-number checks independently of periods.
            $supplier = Supplier::lockForUpdate()->findOrFail($data['supplier_id']);
            if (! $supplier->active) {
                throw new BusinessException('SUPPLIER_INACTIVE', 'المورد غير نشط.');
            }
            $number = self::normalizeNumber($data['supplier_invoice_no']);
            $duplicate = SupplierInvoice::where('supplier_id', $supplier->id)->where('supplier_invoice_no', $number)->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()->first();
            if ($duplicate) {
                throw new BusinessException('DUPLICATE_SUPPLIER_INVOICE', 'رقم فاتورة المورد مسجل سابقاً لهذا المورد.', 409);
            }
            if ($id && $doc->supplier_id !== $supplier->id && DB::table('document_attachments')->where(['entity_type' => 'supplier_invoice', 'entity_id' => $id])->lockForUpdate()->first()) {
                throw new BusinessException('ATTACHED_INVOICE_SUPPLIER_LOCKED', 'أنشئ مسودة جديدة لتغيير مورد فاتورة ذات مرفقات.');
            }
            $fx = app(CurrencySnapshot::class)->execute($data['currency'], $data['posting_date'], $store->base_currency);
            $prepared = app(PrepareSupplierInvoiceLines::class)->execute($data['lines'], $supplier->id, $data['currency'], $data['posting_date'], $data['invoice_date'], $fx['exchange_rate']);
            $lines = $prepared['lines'];
            unset($prepared['lines']);
            $before = $doc->exists ? $doc->toArray() : null;
            $doc->fill(['supplier_id' => $supplier->id, 'supplier_snapshot' => $supplier->documentIdentity(), 'supplier_invoice_no' => $number, 'invoice_date' => $data['invoice_date'], 'posting_date' => $data['posting_date'], 'due_date' => $data['due_date'], 'notes' => $data['notes'] ?? null, 'variance_reason' => $data['variance_reason'] ?? null, 'status' => 'draft', 'version' => ($doc->version ?? 0) + 1, 'policy_version' => $policy->version, 'policy_snapshot' => $policy->toArray(), 'created_by' => $doc->created_by ?? $actorId, 'updated_by' => $actorId, ...$fx, ...$prepared])->save();
            $doc->lines()->delete();
            $doc->lines()->createMany($lines);
            app(RecordAudit::class)->execute($id ? 'purchasing.invoice_updated' : 'purchasing.invoice_created', 'supplier_invoice', $doc->id, $before, $doc->fresh('lines')->toArray(), $actorId);

            return $doc->fresh('lines');
        }, 5);
    }
}
