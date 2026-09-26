<?php

namespace Tests\Feature;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Identity\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FoundationIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $role = 'owner'): User
    {
        $u = User::factory()->create(['password' => Hash::make('SecurePass123!')]);
        $u->roles()->attach(Role::where('name', $role)->firstOrFail());

        return $u;
    }

    public function test_mysql_8_and_required_schema(): void
    {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        $this->assertStringNotContainsString('MariaDB', $version);
        $this->assertTrue(version_compare($version, '8.0.0', '>='));
        $this->assertSame('database', config('queue.default'));
        foreach (['jobs', 'job_batches', 'failed_jobs', 'audit_logs', 'roles', 'permissions'] as $table) {
            $this->assertTrue(\Schema::hasTable($table));
        }
    }

    public function test_login_logout_and_attributed_masked_audit(): void
    {
        $u = $this->user();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePass123!'])->assertOk()->assertJsonPath('data.is_owner', true)->assertJsonMissingPath('data.password');
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $u->id, 'action' => 'auth.login']);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $u->id, 'action' => 'auth.logout']);
        app(RecordAudit::class)->execute('test.mask', 'test', 1, after: ['nested' => ['password' => 'secret']]);
        $this->assertStringNotContainsString('secret', DB::table('audit_logs')->where('action', 'test.mask')->value('after_json'));
    }

    public function test_anonymous_and_cashier_cannot_manage_users_or_audit(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $u = $this->user('cashier');
        $this->actingAs($u)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($u)->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->actingAs($u)->postJson('/api/v1/roles', [])->assertForbidden();
    }

    public function test_disabled_existing_session_is_rejected(): void
    {
        $u = $this->user('cashier');
        $this->actingAs($u);
        $u->update(['status' => 'disabled']);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_last_owner_cannot_be_disabled_or_demoted(): void
    {
        $u = $this->user();
        $cashier = Role::where('name', 'cashier')->firstOrFail();
        $this->actingAs($u)->putJson('/api/v1/users/'.$u->id, ['name' => $u->name, 'email' => $u->email, 'status' => 'active', 'role_ids' => [$cashier->id]])->assertUnprocessable()->assertJsonPath('code', 'LAST_OWNER_REQUIRED');
        $this->assertTrue($u->fresh()->isOwner());
    }

    public function test_owner_can_create_user_and_changes_are_audited(): void
    {
        $u = $this->user();
        $role = Role::where('name', 'cashier')->firstOrFail();
        $this->actingAs($u)->postJson('/api/v1/users', ['name' => 'موظف المبيعات', 'email' => 'cashier@example.test', 'password' => 'NewPassword123!', 'status' => 'active', 'role_ids' => [$role->id]])->assertCreated()->assertJsonPath('data.is_owner', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'users.created', 'actor_user_id' => $u->id]);
    }

    public function test_audit_database_rejects_mutation(): void
    {
        $log = app(RecordAudit::class)->execute('test', 'test', 1);
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
    }

    public function test_invalid_login_is_generic_and_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'absent@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'absent@example.test', 'password' => 'wrong'])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertJsonPath('message', 'محاولات كثيرة خلال وقت قصير. انتظر قليلاً ثم أعد المحاولة.')->assertHeader('Retry-After');
    }
}
