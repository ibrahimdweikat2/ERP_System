<?php

namespace App\Domains\Documents\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\RecordUsage;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a document that was never posted. A draft has no ledger, stock or tax effect, so
 * removing it loses nothing but the draft itself, and the audit log keeps a full copy.
 * Posted (or submitted) documents are retained: the attempt is logged and rejected, and they
 * are corrected by reversal, return or cancellation instead.
 */
class DeleteDraftDocument
{
    /**
     * table: the document; lines: its own line tables (FK column), deleted with it;
     * statuses: states that may be deleted; approvals: where its approval_id points;
     * reject: [error code, message, audit action] for a document that is not a draft.
     */
    public const DOCUMENTS = [
        'sales_invoice' => ['table' => 'sales_invoices', 'lines' => ['sales_invoice_lines' => 'sales_invoice_id'], 'statuses' => ['draft'], 'approvals' => 'workflow_approvals',
            'reject' => ['INVOICE_RETAINED', 'الفاتورة المرحّلة محفوظة؛ استخدم مسار المرتجع لتصحيح المبيعات.', 'sales.delete_rejected']],
        'sales_return' => ['table' => 'sales_returns', 'lines' => ['sales_return_lines' => 'sales_return_id'], 'statuses' => ['draft'], 'approvals' => 'workflow_approvals',
            'reject' => ['DOCUMENT_RETAINED', 'المرتجع المرحّل محفوظ ولا يُحذف.', 'sales.return_delete_rejected']],
        'customer_payment' => ['table' => 'customer_payments', 'lines' => [], 'statuses' => ['draft'], 'approvals' => null,
            'reject' => ['DOCUMENT_RETAINED', 'سند القبض المرحّل محفوظ ولا يُحذف؛ صحّحه بتسوية أو استرداد.', 'payments.delete_rejected']],
        'customer_settlement' => ['table' => 'customer_settlements', 'lines' => ['customer_settlement_allocations' => 'settlement_id'], 'statuses' => ['draft'], 'approvals' => 'workflow_approvals',
            'reject' => ['DOCUMENT_RETAINED', 'التسوية المرحّلة محفوظة ولا تُحذف.', 'customers.settlement_delete_rejected']],
        'purchase_order' => ['table' => 'purchase_orders', 'lines' => ['purchase_order_lines' => 'purchase_order_id'], 'statuses' => ['draft', 'rejected'], 'approvals' => 'approvals',
            'reject' => ['PURCHASE_ORDER_DELETE_FORBIDDEN', 'أمر الشراء المعتمد أو المُصدَر محفوظ للتدقيق ولا يُحذف.', 'purchasing.order_deletion_rejected']],
        'goods_receipt' => ['table' => 'goods_receipts', 'lines' => ['goods_receipt_lines' => 'goods_receipt_id'], 'statuses' => ['draft'], 'approvals' => null,
            'reject' => ['RECEIPT_DELETE_FORBIDDEN', 'سند الاستلام المرحّل محفوظ للتدقيق ولا يُحذف.', 'purchasing.receipt_deletion_rejected']],
        'supplier_invoice' => ['table' => 'supplier_invoices', 'lines' => ['supplier_invoice_lines' => 'supplier_invoice_id'], 'statuses' => ['draft'], 'approvals' => null,
            'reject' => ['INVOICE_DELETE_FORBIDDEN', 'فاتورة المورد المرحّلة محفوظة للتدقيق ولا تُحذف.', 'purchasing.invoice_deletion_rejected']],
        'supplier_payment' => ['table' => 'supplier_payments', 'lines' => ['supplier_payment_allocations' => 'supplier_payment_id'], 'statuses' => ['draft'], 'approvals' => null,
            'reject' => ['POSTED_DOCUMENT_RETAINED', 'الدفعة المرحّلة محفوظة ولا تُحذف.', 'purchasing.settlement_delete_rejected']],
        'supplier_credit_note' => ['table' => 'supplier_credit_notes', 'lines' => ['supplier_credit_note_lines' => 'supplier_credit_note_id'], 'statuses' => ['draft'], 'approvals' => null,
            'reject' => ['POSTED_DOCUMENT_RETAINED', 'الإشعار الدائن المرحّل محفوظ ولا يُحذف.', 'purchasing.settlement_delete_rejected']],
        'stock_adjustment' => ['table' => 'stock_adjustments', 'lines' => ['stock_adjustment_lines' => 'stock_adjustment_id'], 'statuses' => ['draft', 'rejected'], 'approvals' => 'approvals',
            'reject' => ['STOCK_DOCUMENT_DELETE_FORBIDDEN', 'مستند المخزون المرحّل أو قيد الموافقة محفوظ ولا يُحذف.', 'inventory.deletion_rejected']],
        'stock_transfer' => ['table' => 'stock_transfers', 'lines' => ['stock_transfer_lines' => 'stock_transfer_id'], 'statuses' => ['draft', 'rejected'], 'approvals' => 'approvals',
            'reject' => ['STOCK_DOCUMENT_DELETE_FORBIDDEN', 'مستند المخزون المرحّل أو قيد الموافقة محفوظ ولا يُحذف.', 'inventory.deletion_rejected']],
        'stock_count' => ['table' => 'stock_counts', 'lines' => ['stock_count_lines' => 'stock_count_id'], 'statuses' => ['draft', 'rejected'], 'approvals' => 'approvals',
            'reject' => ['STOCK_DOCUMENT_DELETE_FORBIDDEN', 'مستند المخزون المرحّل أو قيد الموافقة محفوظ ولا يُحذف.', 'inventory.deletion_rejected']],
        'journal_entry' => ['table' => 'journal_entries', 'lines' => ['journal_lines' => 'journal_entry_id'], 'statuses' => ['draft'], 'approvals' => null,
            'reject' => ['JOURNAL_DELETE_FORBIDDEN', 'القيد المرحّل لا يُحذف؛ استخدم العكس.', 'accounting.deletion_rejected']],
        'expense' => ['table' => 'expenses', 'lines' => [], 'statuses' => ['draft'], 'approvals' => 'workflow_approvals',
            'reject' => ['DOCUMENT_RETAINED', 'المصروف المرحّل محفوظ ولا يُحذف.', 'treasury.delete_rejected']],
        'cash_transfer' => ['table' => 'cash_transfers', 'lines' => [], 'statuses' => ['draft'], 'approvals' => 'workflow_approvals',
            'reject' => ['DOCUMENT_RETAINED', 'التحويل المرحّل محفوظ ولا يُحذف.', 'treasury.delete_rejected']],
    ];

    public function execute(string $kind, int $id, int $actorId): void
    {
        $config = self::DOCUMENTS[$kind] ?? throw new BusinessException('UNKNOWN_DOCUMENT', 'نوع المستند غير معروف.', 404);
        $rejected = null;
        DB::transaction(function () use ($kind, $id, $actorId, $config, &$rejected) {
            // Same exclusive lock as posting: the draft cannot be posted while it is being removed.
            StoreSetting::lockForUpdate()->findOrFail(1);
            $doc = DB::table($config['table'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($doc, 404);
            if (! in_array($doc->status, $config['statuses'], true)) {
                $rejected = $doc->status;

                return;
            }
            if (($doc->approval_id ?? null) && $config['approvals'] && DB::table($config['approvals'])->where('id', $doc->approval_id)->where('status', 'pending')->exists()) {
                throw new BusinessException('DRAFT_APPROVAL_PENDING', 'للمسودة طلب موافقة معلّق. اتخذ قرار الموافقة أولاً ثم احذفها.', 409);
            }
            // Attachments are immutable records, so a draft holding one is kept.
            if (DB::table('document_attachments')->where('entity_type', $kind)->where('entity_id', $id)->exists()) {
                throw new BusinessException('DRAFT_HAS_ATTACHMENTS', 'للمسودة مرفقات محفوظة، لذلك لا تُحذف.', 409);
            }
            $used = RecordUsage::of($config['table'], $doc, array_keys($config['lines']));
            $lines = [];
            foreach ($config['lines'] as $table => $column) {
                $lines[$table] = DB::table($table)->where($column, $id)->lockForUpdate()->get()->all();
                foreach ($lines[$table] as $line) {
                    $used = [...$used, ...RecordUsage::of($table, $line)];
                }
            }
            if ($used) {
                throw new BusinessException('DRAFT_IN_USE', 'لا يمكن حذف المسودة لأنها مرتبطة بـ: '.implode('، ', array_unique($used)).'.', 409);
            }
            foreach ($config['lines'] as $table => $column) {
                DB::table($table)->where($column, $id)->delete();
            }
            DB::table($config['table'])->where('id', $id)->delete();
            app(RecordAudit::class)->execute('documents.draft_deleted', $kind, $id, ['document' => (array) $doc, 'lines' => $lines], [], $actorId);
        }, 5);
        if ($rejected !== null) {
            // Logged outside the transaction so the rejection record survives.
            [$code, $message, $action] = $config['reject'];
            app(RecordAudit::class)->execute($action, $kind, $id, after: ['status' => $rejected], actorId: $actorId);
            throw new BusinessException($code, $message);
        }
    }
}
