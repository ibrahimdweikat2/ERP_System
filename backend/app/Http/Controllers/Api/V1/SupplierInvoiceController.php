<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Accounting\Models\Account;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Purchasing\Actions\PostSupplierInvoice;
use App\Domains\Purchasing\Actions\SaveSupplierInvoice;
use App\Domains\Purchasing\Models\GoodsReceipt;
use App\Domains\Purchasing\Models\PurchasingInvoicePolicy;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSupplierInvoiceRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Http\Resources\SupplierInvoiceResource;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\IdempotentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierInvoiceController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        $d = $r->validate(['search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['draft', 'posted'])], 'supplier_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $query = SupplierInvoice::query()->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($d['supplier_id'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))->when($d['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('document_no', 'like', "%$s%")->orWhere('supplier_invoice_no', 'like', "%$s%")->orWhere('supplier_snapshot->legal_name', 'like', "%$s%")));

        return SupplierInvoiceResource::collection($query->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
    }

    public function show(SupplierInvoice $supplierInvoice): SupplierInvoiceResource
    {
        return new SupplierInvoiceResource($supplierInvoice->load('lines'));
    }

    public function store(SaveSupplierInvoiceRequest $r, SaveSupplierInvoice $save, IdempotentRequest $idem): JsonResponse
    {
        $result = $idem->execute($r->user()->id, 'supplier_invoice.create', (string) $r->header('Idempotency-Key'), $r->validated(), fn () => ['id' => $save->execute($r->validated(), $r->user()->id)->id]);

        return (new SupplierInvoiceResource(SupplierInvoice::with('lines')->findOrFail($result['id'])))->response()->setStatusCode(201);
    }

    public function update(SaveSupplierInvoiceRequest $r, SupplierInvoice $supplierInvoice, SaveSupplierInvoice $save): SupplierInvoiceResource
    {
        if ($supplierInvoice->status === 'posted') {
            app(RecordAudit::class)->execute('purchasing.invoice_edit_rejected', 'supplier_invoice', $supplierInvoice->id);
            throw new BusinessException('INVOICE_NOT_EDITABLE', 'الفاتورة المرحّلة محفوظة ولا يمكن تعديلها.', 409);
        }

        return new SupplierInvoiceResource($save->execute($r->validated(), $r->user()->id, $supplierInvoice->id));
    }

    public function post(Request $r, SupplierInvoice $supplierInvoice, PostSupplierInvoice $post, IdempotentRequest $idem): SupplierInvoiceResource
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1']]);
        $result = $idem->execute($r->user()->id, 'supplier_invoice.post.'.$supplierInvoice->id, (string) $r->header('Idempotency-Key'), $d, fn () => ['id' => $post->execute($supplierInvoice->id, (int) $d['version'], $r->user()->id)->id]);

        return new SupplierInvoiceResource(SupplierInvoice::with('lines')->findOrFail($result['id']));
    }

    public function receipts(Request $r): AnonymousResourceCollection
    {
        $d = $r->validate(['supplier_id' => ['required', 'integer', 'exists:suppliers,id'], 'currency' => ['required', 'string', 'size:3'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $receipts = GoodsReceipt::with(['lines.location', 'purchaseOrder:id,document_no'])->where('status', 'posted')->where('supplier_id', $d['supplier_id'])->where('currency', $d['currency'])->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25));
        $ids = $receipts->getCollection()->flatMap(fn ($r) => $r->lines->pluck('id'));
        $used = DB::table('supplier_invoice_lines as l')->join('supplier_invoices as i', 'i.id', '=', 'l.supplier_invoice_id')->whereIn('l.goods_receipt_line_id', $ids)->where('i.status', 'posted')->groupBy('l.goods_receipt_line_id')->selectRaw('l.goods_receipt_line_id,SUM(l.quantity) AS quantity')->pluck('quantity', 'goods_receipt_line_id');
        foreach ($receipts as $receipt) {
            foreach ($receipt->lines as $line) {
                $line->setAttribute('invoiced_quantity', Decimal::money($used[$line->id] ?? '0'));
                $line->setAttribute('uninvoiced_quantity', Decimal::sub($line->quantity, $used[$line->id] ?? '0'));
            }
        }

        return GoodsReceiptResource::collection($receipts);
    }

    public function policy(): JsonResponse
    {
        return response()->json(['data' => PurchasingInvoicePolicy::findOrFail(1)]);
    }

    public function policyAccounts(): JsonResponse
    {
        return response()->json(['data' => Account::where('active', true)->where('account_type', 'expense')->where('is_control_account', false)->orderBy('code')->get(['id', 'code', 'name_ar'])]);
    }

    public function savePolicy(Request $r): JsonResponse
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'price_variance_mode' => ['required', Rule::in(['block', 'post_to_expense'])], 'price_variance_account_id' => ['nullable', 'required_if:price_variance_mode,post_to_expense', 'integer', 'exists:accounts,id'], 'nonrecoverable_tax_mode' => ['required', Rule::in(['block', 'expense'])], 'nonrecoverable_tax_account_id' => ['nullable', 'required_if:nonrecoverable_tax_mode,expense', 'integer', 'exists:accounts,id'], 'require_attachment' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return DB::transaction(function () use ($d, $r) {
            StoreSetting::lockForUpdate()->findOrFail(1);
            $policy = PurchasingInvoicePolicy::lockForUpdate()->findOrFail(1);
            if ($policy->version !== (int) $d['version']) {
                throw new BusinessException('POLICY_VERSION_CONFLICT', 'تغيرت السياسة. أعد تحميلها.', 409);
            }
            foreach (['price_variance_account_id', 'nonrecoverable_tax_account_id'] as $field) {
                if ($d[$field] ?? null) {
                    $account = Account::sharedLock()->findOrFail($d[$field]);
                    if (! $account->active || $account->account_type !== 'expense' || $account->is_control_account) {
                        throw new BusinessException('PURCHASE_EXPENSE_ACCOUNT_INVALID', 'اختر حساب مصروف نشطاً غير رقابي.');
                    }
                }
            }
            $before = $policy->toArray();
            $reason = $d['reason'];
            unset($d['reason']);
            $policy->update([...$d, 'version' => $policy->version + 1]);
            app(RecordAudit::class)->execute('purchasing.invoice_policy_changed', 'purchasing_invoice_policy', 1, $before, [...$policy->toArray(), 'reason' => $reason], $r->user()->id);

            return response()->json(['data' => $policy]);
        }, 5);
    }
}
