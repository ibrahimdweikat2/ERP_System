<?php

namespace App\Domains\Reporting\Actions;

use App\Domains\Platform\Models\Company;
use App\Support\MySqlDump;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\TenancySchema;
use Illuminate\Support\Facades\DB;

/**
 * Writes the current company's rows to a data-only SQL file and records it in company_backups.
 *
 * Every company table carries company_id (see VerifyTenancy), so filtering each one on it
 * yields exactly this company's data; platform rows (company_id NULL) are left out. The file
 * holds INSERTs only: a DROP or CREATE TABLE in it would wipe every other company on import.
 */
class BackupCompanyData
{
    private const OPTIONS = [
        '--single-transaction', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF',
        '--no-create-info', '--skip-add-drop-table', '--skip-triggers', '--skip-add-locks', '--skip-disable-keys', '--complete-insert',
    ];

    public function __construct(private CompanyContext $context) {}

    /** Returns false when the backup failed; the row then says so. A backup no longer queued (deleted, or taken by another run) is skipped. */
    public function execute(int $backupId): bool
    {
        $companyId = $this->context->requireCompanyId();
        if (! DB::table('company_backups')->where(['id' => $backupId, 'status' => 'queued'])->update(['status' => 'processing', 'started_at' => now()])) {
            return true;
        }
        $name = 'company-'.$companyId.'-'.now()->format('Ymd-His').'-'.$backupId.'.sql';
        $relative = 'companies/'.$companyId.'/'.$name;
        $file = self::root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parts = [$file.'.company.part', $file.'.data.part'];
        try {
            $folder = dirname($file);
            if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
                throw new \RuntimeException('Backup destination unavailable');
            }
            MySqlDump::run([...self::OPTIONS, '--where=id='.$companyId], $parts[0], ['companies']);
            // company_backups describes the backups themselves, not business data.
            MySqlDump::run([...self::OPTIONS, '--where=company_id='.$companyId], $parts[1], array_values(array_diff(TenancySchema::companyTables(), ['company_backups'])));
            $this->assemble($file, $companyId, $parts);
            DB::table('company_backups')->where('id', $backupId)->update([
                'status' => 'completed', 'path' => $relative, 'file_name' => $name,
                'size' => filesize($file), 'checksum' => hash_file('sha256', $file), 'completed_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            if (is_file($file)) {
                unlink($file);
            }
            DB::table('company_backups')->where('id', $backupId)->update(['status' => 'failed', 'error' => 'تعذر إنشاء النسخة الاحتياطية؛ راجع إعدادات الخادم وسجلاته.', 'completed_at' => now()]);
            report($e);

            return false;
        } finally {
            foreach ($parts as $part) {
                if (is_file($part)) {
                    unlink($part);
                }
            }
        }
    }

    /** The backup file on disk, only if it really lies inside the company's own folder. */
    public static function file(?string $path, int $companyId): ?string
    {
        if (! $path) {
            return null;
        }
        $folder = realpath(self::root().DIRECTORY_SEPARATOR.'companies'.DIRECTORY_SEPARATOR.$companyId);
        $file = realpath(self::root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));

        return $folder && $file && str_starts_with($file, $folder.DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    private static function root(): string
    {
        return rtrim(config('erp.backup_directory'), '/\\');
    }

    /** Header, then the company row, then its data; streamed, so large companies stay out of memory. */
    private function assemble(string $file, int $companyId, array $parts): void
    {
        $company = str_replace(["\r", "\n"], ' ', (string) Company::query()->whereKey($companyId)->value('name'));
        $out = fopen($file, 'xb');
        if (! $out) {
            throw new \RuntimeException('Cannot create backup file');
        }
        try {
            fwrite($out, "-- Company backup (data only)\n-- Company: $companyId - $company\n-- Created: ".now()->toIso8601String()
                ."\n-- Schema: ".DB::table('migrations')->orderByDesc('id')->value('migration')
                ."\n-- INSERT statements only, no CREATE/DROP TABLE. Import only into a database migrated to this schema\n-- that does not already hold this company's rows.\n\n");
            foreach ($parts as $part) {
                $in = fopen($part, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
    }
}
