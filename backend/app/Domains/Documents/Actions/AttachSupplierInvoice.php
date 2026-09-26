<?php

namespace App\Domains\Documents\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Documents\Models\DocumentAttachment;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachSupplierInvoice
{
    public function execute(int $invoiceId, UploadedFile $file, int $actorId): DocumentAttachment
    {
        if (! User::findOrFail($actorId)->hasPermission('purchasing.invoice')) {
            throw new BusinessException('ATTACHMENT_FORBIDDEN', 'إرفاق الفاتورة يتطلب صلاحية فواتير الموردين.', 403);
        }
        $checksum = hash_file('sha256', $file->getRealPath());
        $mime = $file->getMimeType();
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if (! $extension || $file->getSize() > 10 * 1024 * 1024) {
            throw new BusinessException('ATTACHMENT_INVALID', 'المرفق يجب أن يكون PDF أو JPEG أو PNG بحد أقصى 10 ميغابايت.');
        }
        $name = mb_substr(str_replace(['\\', '/', "\r", "\n"], '_', $file->getClientOriginalName()), 0, 255);
        $path = 'supplier-invoices/'.$invoiceId.'/'.Str::uuid().'.'.$extension;
        Storage::disk('documents')->putFileAs(dirname($path), $file, basename($path));
        try {
            $attachment = DB::transaction(function () use ($invoiceId, $file, $actorId, $path, $checksum, $mime, $name) {
                SupplierInvoice::lockForUpdate()->findOrFail($invoiceId);
                $existing = DocumentAttachment::where(['entity_type' => 'supplier_invoice', 'entity_id' => $invoiceId, 'checksum' => $checksum])->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
                $attachment = DocumentAttachment::create(['entity_type' => 'supplier_invoice', 'entity_id' => $invoiceId, 'original_name' => $name, 'stored_path' => $path, 'mime_type' => $mime, 'size' => $file->getSize(), 'checksum' => $checksum, 'uploaded_by' => $actorId, 'visibility_classification' => 'financial_private', 'created_at' => now()]);
                app(RecordAudit::class)->execute('documents.attachment_added', 'supplier_invoice', $invoiceId, null, ['attachment_id' => $attachment->id, 'original_name' => $name, 'mime_type' => $mime, 'size' => $file->getSize(), 'checksum' => $checksum], $actorId);

                return $attachment;
            }, 5);
        } catch (\Throwable $error) {
            // Preserve the file if commit outcome cannot be checked. An orphan is
            // preferable to removing a source file referenced by committed metadata.
            try {
                if (! DocumentAttachment::where('stored_path', $path)->exists()) {
                    Storage::disk('documents')->delete($path);
                }
            } catch (\Throwable) {
            }
            throw $error;
        }
        if ($attachment->stored_path !== $path) {
            Storage::disk('documents')->delete($path);
        }

        return $attachment;
    }
}
