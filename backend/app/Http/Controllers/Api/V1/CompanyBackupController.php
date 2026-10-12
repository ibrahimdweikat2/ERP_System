<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Reporting\Actions\BackupCompanyData;
use App\Domains\Reporting\Jobs\CreateCompanyBackup;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The signed-in company's own backups (data of this company only, see BackupCompanyData).
 * The tenancy grammar filters company_backups, so another company's backup is simply not found.
 */
class CompanyBackupController extends Controller
{
    private const FIELDS = [
        'company_backups.id', 'company_backups.status', 'company_backups.file_name', 'company_backups.size',
        'company_backups.checksum', 'company_backups.error', 'company_backups.created_at',
        'company_backups.started_at', 'company_backups.completed_at', 'users.name as requested_by_name',
    ];

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->rows()->limit(50)->get(self::FIELDS)]);
    }

    public function store(Request $r, CompanyContext $context, RecordAudit $audit): JsonResponse
    {
        $id = DB::transaction(function () use ($r, $context, $audit) {
            // One request at a time per company; a backup unfinished after two hours counts as stuck.
            DB::table('companies')->where('id', $context->requireCompanyId())->lockForUpdate()->first();
            if (DB::table('company_backups')->whereIn('status', ['queued', 'processing'])->where('created_at', '>=', now()->subHours(2))->exists()) {
                throw new BusinessException('BACKUP_IN_PROGRESS', 'توجد نسخة احتياطية قيد الإنشاء. انتظر حتى تكتمل.', 409);
            }
            $id = DB::table('company_backups')->insertGetId(['status' => 'queued', 'requested_by' => $r->user()->id, 'created_at' => now()]);
            $audit->execute('backups.requested', 'company_backup', $id, null, [], $r->user()->id);
            CreateCompanyBackup::dispatch($id)->afterCommit();

            return $id;
        });

        return response()->json(['data' => $this->rows()->where('company_backups.id', $id)->first(self::FIELDS)], 202);
    }

    public function download(Request $r, int $id, CompanyContext $context, RecordAudit $audit): BinaryFileResponse
    {
        $backup = DB::table('company_backups')->where('id', $id)->first();
        abort_unless($backup, 404);
        if ($backup->status !== 'completed') {
            throw new BusinessException('BACKUP_NOT_READY', 'النسخة الاحتياطية لم تكتمل بعد.', 409);
        }
        $file = BackupCompanyData::file($backup->path, $context->requireCompanyId());
        if (! $file) {
            throw new BusinessException('BACKUP_FILE_MISSING', 'ملف النسخة الاحتياطية غير موجود على الخادم.', 404);
        }
        $audit->execute('backups.downloaded', 'company_backup', $id, null, ['file_name' => $backup->file_name], $r->user()->id);

        return response()->download($file, $backup->file_name, ['Content-Type' => 'application/sql; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Deletes the file and its row. The company's live data is untouched; the audit trail keeps who deleted what. */
    public function destroy(Request $r, int $id, CompanyContext $context, RecordAudit $audit): JsonResponse
    {
        DB::transaction(function () use ($r, $id, $context, $audit) {
            $backup = DB::table('company_backups')->where('id', $id)->lockForUpdate()->first();
            abort_unless($backup, 404);
            if ($backup->status === 'processing') {
                throw new BusinessException('BACKUP_IN_PROGRESS', 'لا يمكن حذف نسخة قيد الإنشاء. انتظر حتى تكتمل.', 409);
            }
            $file = BackupCompanyData::file($backup->path, $context->requireCompanyId());
            DB::table('company_backups')->where('id', $id)->delete();
            $audit->execute('backups.deleted', 'company_backup', $id, (array) $backup, null, $r->user()->id);
            // Last, so a file that cannot be removed rolls the row back instead of leaving an orphan.
            if ($file && ! unlink($file)) {
                throw new \RuntimeException('Cannot delete backup file');
            }
        });

        return response()->json(['message' => 'تم حذف النسخة الاحتياطية.']);
    }

    private function rows()
    {
        return DB::table('company_backups')->leftJoin('users', 'users.id', '=', 'company_backups.requested_by')->orderByDesc('company_backups.id');
    }
}
