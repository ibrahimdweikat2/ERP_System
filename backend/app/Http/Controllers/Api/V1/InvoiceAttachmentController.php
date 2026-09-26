<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Documents\Actions\AttachSupplierInvoice;
use App\Domains\Documents\Models\DocumentAttachment;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceAttachmentController extends Controller
{
    public function index(SupplierInvoice $supplierInvoice): JsonResponse
    {
        return response()->json(['data' => DocumentAttachment::where(['entity_type' => 'supplier_invoice', 'entity_id' => $supplierInvoice->id])->orderBy('id')->get()]);
    }

    public function store(Request $r, SupplierInvoice $supplierInvoice, AttachSupplierInvoice $attach): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']]);

        return response()->json(['data' => $attach->execute($supplierInvoice->id, $r->file('file'), $r->user()->id)], 201);
    }

    public function download(SupplierInvoice $supplierInvoice, DocumentAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->entity_type === 'supplier_invoice' && $attachment->entity_id === $supplierInvoice->id, 404);
        abort_unless(Storage::disk('documents')->exists($attachment->stored_path), 404);
        app(RecordAudit::class)->execute('documents.attachment_downloaded', 'supplier_invoice', $supplierInvoice->id, null, ['attachment_id' => $attachment->id]);

        return Storage::disk('documents')->download($attachment->stored_path, $attachment->original_name, ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
