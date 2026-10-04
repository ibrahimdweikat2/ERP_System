<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Approvals\Actions\OwnerAutoApproval;
use App\Domains\Approvals\Models\Approval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Purchasing\Models\PurchaseOrder;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\Currency;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class PurchaseOrderWorkflow
{
    public function payload(PurchaseOrder $doc): array
    {
        return [...$doc->only(['id', 'version', 'supplier_id', 'supplier_snapshot', 'document_date', 'expected_on', 'supplier_reference', 'currency', 'base_currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source', 'subtotal', 'tax_total', 'total', 'base_total', 'notes']), 'lines' => $doc->lines->map(fn ($line) => $line->attributesToArray())->all()];
    }

    private function hash(PurchaseOrder $doc): string
    {
        return hash('sha256', json_encode($this->payload($doc), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function locked(int $id, int $version): PurchaseOrder
    {
        StoreSetting::sharedCurrent();
        $doc = PurchaseOrder::with('lines')->lockForUpdate()->findOrFail($id);
        if ($doc->version !== $version) {
            throw new BusinessException('DOCUMENT_VERSION_CONFLICT', 'تغير أمر الشراء. أعد تحميله.', 409);
        }

        return $doc;
    }

    private function validateMasters(PurchaseOrder $doc): void
    {
        if (! Currency::where('code', $doc->currency)->where('is_active', true)->sharedLock()->first()) {
            throw new BusinessException('CURRENCY_INACTIVE', 'العملة غير نشطة.');
        }
        if (! Supplier::whereKey($doc->supplier_id)->where('active', true)->sharedLock()->first()) {
            throw new BusinessException('SUPPLIER_INACTIVE', 'المورد غير نشط.');
        }
        $products = Product::whereIn('id', $doc->lines->pluck('product_id'))->orderBy('id')->sharedLock()->get();
        if ($products->contains(fn ($p) => ! $p->active)) {
            throw new BusinessException('PRODUCT_INACTIVE', 'أحد المنتجات غير نشط.');
        }
    }

    public function submit(int $id, int $version, int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $version, $actorId) {
            $doc = $this->locked($id, $version);
            if (in_array($doc->status, ['pending', 'approved'], true)) {
                return $doc->load('approval');
            }
            if ($doc->status !== 'draft') {
                throw new BusinessException('PURCHASE_ORDER_NOT_DRAFT', 'أعد تحرير أمر الشراء قبل طلب الموافقة.');
            }
            $this->validateMasters($doc);
            $policy = DB::table('approval_policies')->where('key', 'purchase_order')->sharedLock()->first();
            $required = Decimal::cmp($doc->base_total, $policy->threshold) >= 0;
            $hash = $this->hash($doc);
            $approval = $required ? Approval::create(['source_type' => 'purchase_order', 'source_id' => $id, 'source_version' => $version, 'payload_hash' => $hash, 'payload_json' => $this->payload($doc), 'amount' => $doc->base_total, 'currency' => $doc->base_currency, 'policy_key' => 'purchase_order', 'policy_version' => $policy->version, 'segregate_requester' => $policy->segregate_requester, 'status' => 'pending', 'requested_by' => $actorId]) : null;
            $pending = $required && ! app(OwnerAutoApproval::class)->apply($approval, $actorId);
            $doc->update(['status' => $pending ? 'pending' : 'approved', 'approval_id' => $approval?->id, 'approved_payload_hash' => $hash, 'approval_policy_version' => $policy->version]);
            app(RecordAudit::class)->execute('purchasing.order_submitted', 'purchase_order', $id, null, ['version' => $version, 'approval_id' => $approval?->id, 'base_total' => $doc->base_total, 'policy_version' => $policy->version, 'automatic' => ! $required], $actorId);

            return $doc->load('approval');
        }, 5);
    }

    public function decide(int $id, int $version, string $decision, string $reason, int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $version, $decision, $reason, $actorId) {
            if (! User::findOrFail($actorId)->hasPermission('purchasing.approve')) {
                throw new BusinessException('APPROVAL_PERMISSION_REQUIRED', 'اعتماد أمر الشراء يحتاج صلاحية اعتماد المشتريات.', 403);
            }
            $doc = $this->locked($id, $version);
            $approval = $doc->approval_id ? Approval::lockForUpdate()->findOrFail($doc->approval_id) : null;
            if ($approval && $approval->status === $decision && $doc->status === $decision) {
                return $doc->load('approval');
            }
            if (! $approval || $doc->status !== 'pending' || $approval->status !== 'pending' || $approval->source_version !== $version) {
                throw new BusinessException('APPROVAL_STALE', 'طلب الموافقة لم يعد سارياً.', 409);
            }
            if ($approval->segregate_requester && in_array($actorId, [$approval->requested_by, $doc->created_by, $doc->updated_by], true)) {
                throw new BusinessException('SELF_APPROVAL_FORBIDDEN', 'الموافقة تتطلب مستخدماً آخر وفق سياسة فصل المهام.', 403);
            }
            $policy = DB::table('approval_policies')->where('key', 'purchase_order')->sharedLock()->first();
            if ($decision === 'approved' && ($policy->version !== $approval->policy_version || ! hash_equals($approval->payload_hash, $this->hash($doc)))) {
                throw new BusinessException('APPROVAL_PAYLOAD_CHANGED', 'تغيرت البيانات أو السياسة. أعد تحرير الأمر وطلب الموافقة.', 409);
            }
            $approval->update(['status' => $decision, 'decided_by' => $actorId, 'decided_at' => now(), 'decision_reason' => $reason]);
            DB::table('approval_steps')->insert(['approval_id' => $approval->id, 'decision' => $decision, 'actor_id' => $actorId, 'reason' => $reason, 'occurred_at' => now()]);
            $doc->update(['status' => $decision]);
            app(RecordAudit::class)->execute('purchasing.order_'.$decision, 'purchase_order', $id, null, ['approval_id' => $approval->id, 'version' => $version, 'reason' => $reason], $actorId);

            return $doc->load('approval');
        }, 5);
    }

    public function issue(int $id, int $version, int $actorId): PurchaseOrder
    {
        return DB::transaction(function () use ($id, $version, $actorId) {
            $doc = $this->locked($id, $version);
            if ($doc->status === 'issued') {
                return $doc->load('approval');
            }
            if ($doc->status !== 'approved') {
                throw new BusinessException('PURCHASE_ORDER_NOT_APPROVED', 'أكمل الموافقة قبل إصدار أمر الشراء.');
            }
            $this->validateMasters($doc);
            $policy = DB::table('approval_policies')->where('key', 'purchase_order')->sharedLock()->first();
            $approval = $doc->approval_id ? Approval::lockForUpdate()->findOrFail($doc->approval_id) : null;
            if ($doc->approval_policy_version !== $policy->version || ! hash_equals($doc->approved_payload_hash ?? '', $this->hash($doc)) || ($approval && ($approval->status !== 'approved' || $approval->source_version !== $version || ! hash_equals($approval->payload_hash, $this->hash($doc))))) {
                throw new BusinessException('APPROVAL_PAYLOAD_CHANGED', 'تغيرت البيانات أو السياسة. أعد تحرير الأمر وطلب الموافقة.', 409);
            }
            $doc->update(['document_no' => app(NextDocumentNumber::class)->execute('purchase_order', $doc->document_date), 'status' => 'issued', 'issued_at' => now(), 'issued_by' => $actorId]);
            app(RecordAudit::class)->execute('purchasing.order_issued', 'purchase_order', $id, null, ['document_no' => $doc->document_no, 'version' => $version, 'base_total' => $doc->base_total, 'approval_id' => $doc->approval_id], $actorId);

            return $doc->load('approval');
        }, 5);
    }
}
