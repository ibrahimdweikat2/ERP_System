<?php

namespace Tests\Feature;

use App\Domains\Identity\Models\Role;
use App\Domains\Purchasing\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->loginRole('owner');
    }

    private function loginRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        $this->actingAs($user);
    }

    private function data(array $extra = []): array
    {
        return ['code' => ' supp-1 ', 'legal_name' => 'شركة أجهزة اختبار', 'trade_name' => 'مورد الاختبار', 'tax_number' => 'TEST-TAX', 'address' => 'عنوان تجريبي', 'contacts' => [['name' => 'مندوب المورد', 'phone' => '000000', 'email' => 'supplier@example.test', 'title' => 'المبيعات']], 'currency' => 'ILS', 'payment_terms_days' => 30, 'credit_limit' => '9000.0001', 'active' => true, 'notes' => 'بيانات اختبار', ...$extra];
    }

    private function createSupplier(array $extra = [], string $key = 'supplier-test-create'): int
    {
        return $this->postJson('/api/v1/suppliers', $this->data($extra), ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
    }

    public function test_exact_supplier_master_is_idempotent_and_never_posts_balances(): void
    {
        $id = $this->createSupplier();
        $this->assertSame($id, $this->createSupplier());
        $this->getJson('/api/v1/suppliers?search=SUPP-1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'SUPP-1')->assertJsonPath('data.0.credit_limit', '9000.0001')->assertJsonPath('data.0.contacts.0.email', 'supplier@example.test');
        $this->postJson('/api/v1/suppliers', $this->data(['code' => 'different']), ['Idempotency-Key' => 'supplier-test-create'])->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_CONFLICT');
        $this->postJson('/api/v1/suppliers', $this->data(), ['Idempotency-Key' => 'supplier-other-create'])->assertUnprocessable()->assertJsonPath('code', 'SUPPLIER_CODE_EXISTS');
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'purchasing.supplier_created')->count());
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_bank_details_are_encrypted_private_and_preserved_by_procurement_edits(): void
    {
        $bank = ['bank_name' => 'بنك اختبار', 'beneficiary' => 'مستفيد اختبار', 'iban' => 'TEST-IBAN-PRIVATE-123', 'account_number' => '987654321'];
        $id = $this->createSupplier(['bank_info' => $bank]);
        $this->assertStringNotContainsString($bank['iban'], DB::table('suppliers')->where('id', $id)->value('bank_info'));
        $this->assertSame($bank, Supplier::findOrFail($id)->bank_info);
        $this->assertStringNotContainsString($bank['iban'], DB::table('audit_logs')->where('entity_type', 'supplier')->value('after_json'));
        $this->getJson('/api/v1/suppliers/'.$id)->assertOk()->assertJsonPath('data.bank_info.iban', $bank['iban']);
        $this->loginRole('inventory');
        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonMissingPath('data.0.bank_info');
        $this->getJson('/api/v1/suppliers/'.$id)->assertOk()->assertJsonMissingPath('data.bank_info');
        $this->putJson('/api/v1/suppliers/'.$id, $this->data(['version' => 1, 'bank_info' => ['iban' => 'REPLACED']]))->assertUnprocessable();
        $this->putJson('/api/v1/suppliers/'.$id, $this->data(['version' => 1, 'address' => 'عنوان جديد']))->assertOk()->assertJsonPath('data.version', 2)->assertJsonMissingPath('data.bank_info');
        $this->assertSame($bank, Supplier::findOrFail($id)->bank_info);
        $this->assertSame('عنوان جديد', Supplier::findOrFail($id)->address);
        $this->loginRole('cashier');
        $this->getJson('/api/v1/suppliers')->assertForbidden();
        $this->postJson('/api/v1/suppliers', $this->data(), ['Idempotency-Key' => 'cashier-denied'])->assertForbidden();
    }

    public function test_stale_edits_cannot_overwrite_supplier_terms_and_active_filter_works(): void
    {
        $id = $this->createSupplier();
        $data = $this->data(['version' => 1, 'active' => false, 'payment_terms_days' => 45]);
        $this->putJson('/api/v1/suppliers/'.$id, $data)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/suppliers/'.$id, [...$data, 'active' => true])->assertConflict()->assertJsonPath('code', 'SUPPLIER_VERSION_CONFLICT');
        $this->getJson('/api/v1/suppliers?active=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/suppliers?active=0')->assertOk()->assertJsonPath('data.0.payment_terms_days', 45);
        $this->assertFalse(Supplier::findOrFail($id)->active);
    }

    public function test_supplier_validation_rejects_financial_shortcuts_and_inactive_currency(): void
    {
        foreach ([
            ['credit_limit' => 99.5], ['credit_limit' => '-1'], ['payment_terms_days' => -1],
            ['contacts' => [['name' => 'test', 'email' => 'not-email']]],
            ['opening_balance' => '100'], ['balance' => '100'], ['currency' => 'BAD'],
        ] as $index => $invalid) {
            $this->postJson('/api/v1/suppliers', $this->data($invalid), ['Idempotency-Key' => 'invalid-supplier-'.$index])->assertUnprocessable();
        }
        DB::table('currencies')->where('code', 'USD')->update(['is_active' => false]);
        $this->postJson('/api/v1/suppliers', $this->data(['currency' => 'USD']), ['Idempotency-Key' => 'inactive-currency'])->assertUnprocessable();
        $this->assertDatabaseCount('suppliers', 0);
        $this->loginRole('inventory');
        $this->getJson('/api/v1/purchasing/currencies')->assertOk()->assertJsonMissing(['code' => 'USD']);
    }
}
