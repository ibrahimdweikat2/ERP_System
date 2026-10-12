<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\Role;
use App\Domains\Reporting\Jobs\CreateCompanyBackup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Each company's owner backs up, downloads and deletes only that company's backups. */
class CompanyBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-backup-test-'.uniqid();
        config(['erp.backup_directory' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /** A finished backup with its file on disk, as BackupCompanyData leaves it. */
    private function completedBackup(int $companyId, string $content = "-- data\n"): int
    {
        return $this->companyContext()->run($companyId, function () use ($companyId, $content) {
            $name = "company-$companyId-test.sql";
            File::ensureDirectoryExists($this->root."/companies/$companyId");
            file_put_contents($this->root."/companies/$companyId/$name", $content);

            return DB::table('company_backups')->insertGetId([
                'status' => 'completed', 'path' => "companies/$companyId/$name", 'file_name' => $name, 'size' => strlen($content),
                'checksum' => hash('sha256', $content), 'created_at' => now(), 'started_at' => now(), 'completed_at' => now(),
            ]);
        });
    }

    public function test_owner_requests_a_backup_of_their_own_company(): void
    {
        Queue::fake();
        $owner = $this->companyOwner(1);
        $this->actingAs($owner);
        $id = $this->postJson('/api/v1/backups')->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.requested_by_name', $owner->name)->json('data.id');

        $this->assertDatabaseHas('company_backups', ['id' => $id, 'company_id' => 1, 'status' => 'queued', 'requested_by' => $owner->id]);
        Queue::assertPushedOn('maintenance', CreateCompanyBackup::class, fn (CreateCompanyBackup $job) => $job->backupId === $id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backups.requested', 'entity_id' => $id]);
        // One backup at a time per company.
        $this->postJson('/api/v1/backups')->assertStatus(409);
        $this->getJson('/api/v1/backups')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }

    public function test_a_company_never_sees_or_touches_another_companys_backups(): void
    {
        $second = $this->makeCompany();
        $other = $this->completedBackup($second->id);
        $own = $this->completedBackup(1);
        $this->actingAs($this->companyOwner(1));

        $this->getJson('/api/v1/backups')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
        $this->get("/api/v1/backups/$other/download")->assertNotFound();
        $this->deleteJson("/api/v1/backups/$other")->assertNotFound();
        $this->assertFileExists($this->root."/companies/{$second->id}/company-{$second->id}-test.sql");
    }

    public function test_only_holders_of_the_permission_reach_backups(): void
    {
        $this->assertContains('backups.manage', Role::where('name', 'owner')->firstOrFail()->permissions->pluck('name')->all());

        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);
        $this->getJson('/api/v1/backups')->assertForbidden();
        $this->postJson('/api/v1/backups')->assertForbidden();

        // The platform keeps its own whole-database backup; company backups are not its business.
        $this->actingAs($this->platformAdmin());
        $this->getJson('/api/v1/backups')->assertForbidden();
        $this->postJson('/api/v1/backups')->assertForbidden();
    }

    public function test_download_returns_the_file_and_is_audited(): void
    {
        $id = $this->completedBackup(1, "-- company 1 data\n");
        $pending = DB::table('company_backups')->insertGetId(['status' => 'queued', 'created_at' => now()]);
        $this->actingAs($this->companyOwner(1));

        $response = $this->get("/api/v1/backups/$id/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame("-- company 1 data\n", $response->baseResponse->getFile()->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backups.downloaded', 'entity_id' => $id]);
        $this->getJson("/api/v1/backups/$pending/download")->assertStatus(409);
    }

    public function test_delete_removes_the_file_and_row_but_not_while_processing(): void
    {
        $id = $this->completedBackup(1);
        $running = DB::table('company_backups')->insertGetId(['status' => 'processing', 'created_at' => now(), 'started_at' => now()]);
        $this->actingAs($this->companyOwner(1));

        $this->deleteJson("/api/v1/backups/$id")->assertOk();
        $this->assertFileDoesNotExist($this->root.'/companies/1/company-1-test.sql');
        $this->assertDatabaseMissing('company_backups', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backups.deleted', 'entity_id' => $id]);

        $this->deleteJson("/api/v1/backups/$running")->assertStatus(409);
        $this->assertDatabaseHas('company_backups', ['id' => $running, 'status' => 'processing']);
    }
}
