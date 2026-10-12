<?php

namespace Tests\Feature;

use App\Domains\Reporting\Actions\BackupCompanyData;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Runs the real mysqldump: a company's file holds that company's rows and nothing that could harm others. */
class CompanyBackupDumpTest extends TestCase
{
    // mysqldump reads through its own connection, so fixtures must be committed.
    use DatabaseTruncation;

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_the_dump_holds_only_the_companys_rows_and_no_schema_statements(): void
    {
        try {
            $available = (new Process([config('erp.mysqldump_binary'), '--version']))->run() === 0;
        } catch (\Throwable) {
            $available = false;
        }
        if (! $available) {
            $this->markTestSkipped('mysqldump is not available; set ERP_MYSQLDUMP_BINARY in .env.testing.');
        }
        $this->seed();
        $second = $this->makeCompany('شركة النسخ الثانية');
        $this->companyOwner(1, 'first-company-owner@example.test');
        $this->companyOwner($second, 'second-company-owner@example.test');
        $this->platformAdmin('platform-backup@example.test');
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-dump-test-'.uniqid();
        config(['erp.backup_directory' => $root]);
        try {
            $id = DB::table('company_backups')->insertGetId(['status' => 'queued', 'created_at' => now()]);
            $this->assertTrue(app(BackupCompanyData::class)->execute($id));

            $backup = DB::table('company_backups')->where('id', $id)->first();
            $this->assertSame('completed', $backup->status);
            $sql = file_get_contents(BackupCompanyData::file($backup->path, 1));
            $this->assertSame(hash('sha256', $sql), $backup->checksum);
            $this->assertSame(strlen($sql), (int) $backup->size);

            $this->assertStringContainsString('INSERT INTO `companies`', $sql);
            $this->assertStringContainsString('first-company-owner@example.test', $sql);
            $this->assertStringNotContainsString('second-company-owner@example.test', $sql);
            $this->assertStringNotContainsString('platform-backup@example.test', $sql);
            $this->assertStringNotContainsString('شركة النسخ الثانية', $sql);
            // Importing the file must never drop, recreate or alter shared tables.
            $statements = preg_grep('/^(?!--)/', explode("\n", $sql));
            $this->assertSame([], array_values(preg_grep('/\b(DROP|CREATE|ALTER)\s+(TABLE|TRIGGER)\b|LOCK TABLES/i', $statements)));
            $this->assertSame([], glob($root.'/companies/1/*.part'));
        } finally {
            File::deleteDirectory($root);
        }
    }
}
