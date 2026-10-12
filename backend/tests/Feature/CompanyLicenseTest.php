<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\Role;
use App\Domains\Licensing\CompanyLicensing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A company works only with a valid LicenseBox license: without one every user sees only the
 * dashboard, and the owner adds the license from Administration -> Company license.
 * The LicenseBox server is faked here, signing like the real one with a throwaway RSA key.
 */
class CompanyLicenseTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ABCD-EFGH-JKMN-PQRS';

    private string $dir;

    private string $privateKey = '';

    /** @var callable(string, array): array{0: bool, 1: string, 2: mixed, 3?: string} */
    private $server;

    private int $nextInstance = 1;

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'erp-license-test-'.uniqid();
        mkdir($this->dir);
        // Windows PHP builds need an explicit OpenSSL config to create keys.
        file_put_contents($cnf = $this->dir.'/openssl.cnf', "[ req ]\ndistinguished_name = dn\n[ dn ]\n");
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $this->privateKey, null, $options);
        file_put_contents($this->dir.'/public.pem', openssl_pkey_get_details($key)['key']);

        config(['erp.license' => [
            'enforce' => true, 'server_url' => 'https://licenses.test', 'product_id' => 'PRODUCT1', 'api_key' => 'lb_ext_test',
            'public_key_path' => $this->dir.'/public.pem', 'domain' => 'erp.example.com', 'timeout' => 5,
        ]]);
        $this->server = fn (string $endpoint, array $in) => $this->validLicense($endpoint, $in);
        Http::fake(fn (ClientRequest $request) => $this->respond($request));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_without_a_license_every_user_sees_only_the_dashboard(): void
    {
        $owner = $this->companyOwner(1);
        $this->actingAs($owner);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.licensed', false);
        $this->assertFalse($owner->fresh()->hasPermission('sales.view'));
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.tiles', []);
        $this->getJson('/api/v1/store/context')->assertOk();
        // Routes with and without their own permission check are closed alike.
        $this->getJson('/api/v1/products')->assertForbidden()->assertJsonPath('code', 'LICENSE_REQUIRED');
        $this->getJson('/api/v1/notifications')->assertForbidden()->assertJsonPath('code', 'LICENSE_REQUIRED');
        $this->getJson('/api/v1/company-license')->assertOk()->assertJsonPath('data.state', 'missing')->assertJsonPath('data.server_configured', true);
    }

    public function test_owner_activates_a_license_and_users_get_their_permissions(): void
    {
        $owner = $this->companyOwner(1);
        $cashier = $this->cashier();
        $this->actingAs($owner);

        $this->postJson('/api/v1/company-license', ['license_key' => strtolower(self::KEY)])->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.license_key', 'ABCD•••••••••••PQRS')->assertJsonPath('data.max_activations', 1);
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/activate_license')
            && $r['license_key'] === self::KEY && $r['domain'] === 'erp.example.com' && $r['product_id'] === 'PRODUCT1' && $r->header('X-API-KEY') === ['lb_ext_test']);

        $row = DB::table('company_licenses')->where('company_id', 1)->first();
        $this->assertNotSame(self::KEY, $row->license_key, 'the key is stored encrypted');
        $this->assertSame(hash('sha256', self::KEY), $row->license_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.activated', 'entity_id' => 1, 'actor_user_id' => $owner->id]);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.licensed', true);
        $this->getJson('/api/v1/products')->assertOk();
        // Every user of the company now holds exactly the permissions of their roles.
        $this->actingAs($cashier);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.licensed', true);
        $this->assertTrue($cashier->fresh()->load('roles.permissions')->hasPermission('sales.view'));
        $this->getJson('/api/v1/users')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_only_the_owner_manages_the_license(): void
    {
        $this->actingAs($this->cashier());
        $this->getJson('/api/v1/company-license')->assertForbidden();
        $this->postJson('/api/v1/company-license', ['license_key' => self::KEY])->assertForbidden();
        $this->assertDatabaseCount('company_licenses', 0);

        $this->actingAs($this->platformAdmin());
        $this->getJson('/api/v1/company-license')->assertForbidden();
    }

    public function test_a_key_the_server_refuses_or_cannot_vouch_for_is_not_stored(): void
    {
        $this->actingAs($this->companyOwner(1));

        $this->server = fn () => [false, 'Invalid license key.', null, 'invalid_license'];
        $this->postJson('/api/v1/company-license', ['license_key' => self::KEY])->assertStatus(422)
            ->assertJsonPath('code', 'LICENSE_REJECTED')->assertJsonPath('message', 'مفتاح الترخيص غير صحيح.');

        // A response signed with another key (fake server / man-in-the-middle) is rejected.
        $this->server = fn (string $endpoint, array $in) => $this->validLicense($endpoint, $in);
        $genuine = $this->privateKey;
        $this->privateKey = $this->otherPrivateKey();
        $this->postJson('/api/v1/company-license', ['license_key' => self::KEY])->assertStatus(502)->assertJsonPath('code', 'LICENSE_SERVER_UNREACHABLE');
        $this->privateKey = $genuine;

        $this->server = fn () => [true, 'ok', ['instance_id' => 'x', 'local_key' => 'forged.token']];
        $this->postJson('/api/v1/company-license', ['license_key' => self::KEY])->assertStatus(502)->assertJsonPath('code', 'LICENSE_UNTRUSTED');

        $this->assertDatabaseCount('company_licenses', 0);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.licensed', false);
    }

    public function test_one_license_key_serves_one_company(): void
    {
        $this->activate(1);
        $second = $this->makeCompany();
        $this->actingAs($this->companyOwner($second));

        $this->postJson('/api/v1/company-license', ['license_key' => self::KEY])->assertStatus(409)->assertJsonPath('code', 'LICENSE_IN_USE');
        Http::assertSentCount(1);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.licensed', false);
    }

    public function test_editing_the_stored_license_does_not_extend_it(): void
    {
        $this->activate(1);
        $owner = $this->companyOwner(1);
        $this->actingAs($owner);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.licensed', true);

        // valid_until lives inside the signed local key; a re-signed key is not trusted.
        [$body] = explode('.', DB::table('company_licenses')->value('local_key'));
        $payload = json_decode(base64_decode($body), true);
        $payload['valid_until'] = now()->addYears(50)->timestamp;
        DB::table('company_licenses')->update(['local_key' => base64_encode(json_encode($payload)).'.'.base64_encode('nope')]);
        $this->app->forgetInstance(CompanyLicensing::class);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.licensed', false);
        $this->getJson('/api/v1/company-license')->assertJsonPath('data.state', 'untrusted');
    }

    public function test_online_checks_keep_revoke_and_lapse_the_license(): void
    {
        $this->activate(1);
        $owner = $this->companyOwner(1);
        $this->actingAs($owner);
        $licensing = fn () => $this->app->make(CompanyLicensing::class);

        // Not due yet: nothing is sent.
        $this->assertSame(0, $licensing()->verifyDue());

        // Server unreachable after next_check_at: still valid inside the grace period.
        $this->travel(8)->days();
        $this->down = true;
        $this->app->forgetInstance(CompanyLicensing::class);
        $this->assertSame(1, $licensing()->verifyDue());
        $this->assertTrue($licensing()->isValid(1));
        $this->assertNotNull(DB::table('company_licenses')->value('last_error'));

        // No successful check before valid_until: the license lapses.
        $this->travel(14)->days();
        $this->app->forgetInstance(CompanyLicensing::class);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.licensed', false);
        $this->getJson('/api/v1/company-license')->assertJsonPath('data.state', 'lapsed');

        // The server is back: the owner's "check now" renews it.
        $this->down = false;
        $this->postJson('/api/v1/company-license/verify')->assertOk()->assertJsonPath('data.valid', true);
        $this->getJson('/api/v1/products')->assertOk();

        // Revoked on the server: locked at the next check, and it must be activated again.
        $this->server = fn () => [false, 'This license has been revoked.', null, 'license_revoked'];
        $this->postJson('/api/v1/company-license/verify')->assertOk()->assertJsonPath('data.state', 'invalid')
            ->assertJsonPath('data.message', 'الترخيص ملغى.');
        $this->getJson('/api/v1/products')->assertForbidden()->assertJsonPath('code', 'LICENSE_REQUIRED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.invalid', 'entity_id' => 1]);
    }

    public function test_owner_deactivates_the_license(): void
    {
        $this->activate(1);
        $owner = $this->companyOwner(1);
        $this->actingAs($owner);

        $this->deleteJson('/api/v1/company-license')->assertOk()->assertJsonPath('data.state', 'missing');
        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/deactivate_license') && $r['instance_id'] === 'instance-1');
        $this->assertDatabaseCount('company_licenses', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'license.deactivated', 'actor_user_id' => $owner->id]);
        $this->getJson('/api/v1/products')->assertForbidden();
    }

    private function activate(int $companyId): void
    {
        $owner = $this->companyOwner($companyId);
        $this->actingAs($owner)->postJson('/api/v1/company-license', ['license_key' => self::KEY])->assertOk();
        $this->app->forgetInstance(CompanyLicensing::class);
    }

    private function cashier(): User
    {
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());

        return $cashier;
    }

    /** LicenseBox's answer for a healthy single-seat license. */
    private function validLicense(string $endpoint, array $in): array
    {
        if ($endpoint === 'deactivate_license') {
            return [true, 'License deactivated successfully.', ['activations_used' => 0, 'max_activations' => 1]];
        }
        $instance = $in['instance_id'] ?? 'instance-'.$this->nextInstance++;
        $local = base64_encode(json_encode([
            'license_hash' => hash('sha256', $in['license_key']), 'product_id' => 'PRODUCT1', 'instance_id' => $instance,
            'domain' => $in['domain'] ?? null, 'issued_at' => now()->timestamp,
            'next_check_at' => now()->addDays(7)->timestamp, 'valid_until' => now()->addDays(21)->timestamp,
        ], JSON_UNESCAPED_SLASHES));

        return [true, 'License is valid.', [
            'instance_id' => $instance, 'license_type' => 'lifetime', 'client_name' => $in['client_name'] ?? null, 'domain' => $in['domain'] ?? null,
            'expires_at' => null, 'updates_expire_at' => null, 'activations_used' => 1, 'max_activations' => 1,
            'next_check_at' => now()->addDays(7)->toIso8601String(), 'local_key' => $local.'.'.$this->sign($local),
        ]];
    }

    /** Signed exactly like LicenseBox's ResponseSigner, echoing the nonce. */
    private function respond(ClientRequest $request)
    {
        if ($this->down) {
            return Http::failedConnection();
        }
        $endpoint = basename(parse_url($request->url(), PHP_URL_PATH));
        $reply = ($this->server)($endpoint, $request->data());
        $payload = ['status' => $reply[0], 'message' => $reply[1], 'data' => $reply[2]];
        if (isset($reply[3])) {
            $payload['reason'] = $reply[3];
        }
        $payload['nonce'] = $request['nonce'];
        $payload['timestamp'] = time();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return Http::response($body, $reply[0] ? 200 : 403, ['Content-Type' => 'application/json', 'X-LicenseBox-Signature' => $this->sign($body)]);
    }

    private function sign(string $data): string
    {
        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private function otherPrivateKey(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $this->dir.'/openssl.cnf'];
        openssl_pkey_export(openssl_pkey_new($options), $pem, null, $options);

        return $pem;
    }
}
