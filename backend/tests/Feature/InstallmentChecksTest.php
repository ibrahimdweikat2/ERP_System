<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Checks\Actions\ManageCheck;
use App\Domains\Customers\Models\Customer;
use App\Domains\Identity\Models\Role;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Payments\Actions\ReceiveCustomerPayment;
use App\Domains\Purchasing\Actions\PostGoodsReceipt;
use App\Domains\Purchasing\Actions\SaveGoodsReceipt;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Domains\Sales\Actions\PostSalesInvoice;
use App\Domains\Sales\Actions\SaveSalesInvoice;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Domains\Tax\Models\TaxCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstallmentChecksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $customer;

    private int $invoice;

    private int $contract;

    private int $cashbox;

    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($this->owner);
        app(CreateFiscalYear::class)->execute(['name' => 'check settlement', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        StoreSetting::current()->update(['vat_registered' => true]);
        $this->cashbox = DB::table('cashboxes')->insertGetId(['name' => 'صندوق اختبار', 'account_id' => Account::where('code', '1100')->value('id'), 'currency_code' => 'ILS', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->bank = DB::table('bank_accounts')->insertGetId(['name' => 'بنك اختبار', 'bank_name' => 'بنك اختبار', 'account_number' => '1', 'account_id' => Account::where('code', '1110')->value('id'), 'currency_code' => 'ILS', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tax = TaxCode::create(['code' => 'ZERO', 'name_ar' => 'صفر للاختبار', 'category' => 'standard', 'rate' => '0', 'effective_from' => '2026-01-01', 'input_account_id' => Account::where('code', '1400')->value('id'), 'output_account_id' => Account::where('code', '2200')->value('id')])->id;
        $supplier = app(SaveSupplier::class)->execute(['code' => 'CHK-SUP', 'legal_name' => 'مورد', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 0, 'credit_limit' => '0', 'active' => true], $this->owner->id)->id;
        $product = app(SaveProduct::class)->execute(['sku' => 'CHK-PROD', 'name_ar' => 'جهاز', 'unit_id' => Unit::firstOrFail()->id, 'tax_code_id' => $tax, 'serial_tracked' => false, 'active' => true, 'cash_price' => '1000', 'installment_price' => '1000', 'minimum_price' => '500', 'reorder_level' => '0', 'barcodes' => []], $this->owner->id)->id;
        $warehouse = StockLocation::where('code', 'WAREHOUSE')->value('id');
        $receipt = app(SaveGoodsReceipt::class)->execute(['supplier_id' => $supplier, 'document_date' => '2026-01-02', 'currency' => 'ILS', 'delivery_reference' => 'CHK', 'lines' => [['product_id' => $product, 'location_id' => $warehouse, 'condition' => 'new', 'quantity' => '1', 'unit_cost' => '600', 'serials' => []]]], $this->owner->id);
        app(PostGoodsReceipt::class)->execute($receipt->id, $receipt->version, $this->owner->id);
        $this->customer = Customer::create(['code' => 'CHK-CUS', 'name' => 'عميل الشيك', 'currency' => 'ILS', 'credit_limit' => '100000', 'max_active_contracts' => 5, 'max_overdue_days' => 365, 'risk_flag' => 'normal', 'is_walk_in' => false, 'active' => true, 'version' => 1, 'created_by' => $this->owner->id])->id;
        // Installment price recognised at sale, as the owner configures before live use.
        $policy = DB::table('business_policies')->where('key', 'installments')->first();
        DB::table('business_policies')->where('key', 'installments')->update(['settings' => json_encode(['markup_recognition' => 'full_price_at_sale'] + json_decode($policy->settings, true)), 'version' => $policy->version + 1]);
        $sale = app(SaveSalesInvoice::class)->execute(['customer_id' => $this->customer, 'document_date' => '2026-01-05', 'due_date' => '2026-01-05', 'currency' => 'ILS', 'sale_mode' => 'installment',
            'checkout' => ['amount' => '400', 'method' => 'cash', 'cashbox_id' => $this->cashbox, 'bank_account_id' => null, 'installment_count' => 3, 'first_due_date' => '2026-02-05', 'frequency' => 'monthly', 'schedule' => []],
            'lines' => [['product_id' => $product, 'location_id' => $warehouse, 'quantity' => '1', 'unit_price' => '1000', 'discount_amount' => '0', 'tax_code_id' => $tax, 'tax_inclusive' => true, 'serials' => []]]], $this->owner->id);
        app(PostSalesInvoice::class)->execute($sale->id, $this->owner->id);
        $this->invoice = $sale->id;
        $this->contract = DB::table('installment_contracts')->where('sales_invoice_id', $sale->id)->value('id');
    }

    /** @return list<object> the open installments, oldest first */
    private function installments(): array
    {
        return DB::table('installment_schedule')->where('contract_id', $this->contract)->where('superseded', false)->orderBy('due_date')->get()->all();
    }

    private function batch(array $checks): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/installment-contracts/{$this->contract}/checks", [
            'received_date' => '2026-01-05', 'bank_name' => 'بنك فلسطين', 'bank_branch' => 'نابلس', 'payer_name' => 'عميل الشيك', 'account_reference' => 'ACC-77', 'checks' => $checks]);
    }

    public function test_a_book_of_checks_is_received_and_each_pays_its_own_installment(): void
    {
        [$first, $second, $third] = $this->installments();
        // The first check is tied to the LAST installment: the target wins over oldest-first.
        $response = $this->batch([
            ['check_no' => '1001', 'amount' => '200', 'due_date' => $third->due_date, 'installment_schedule_id' => $third->id],
            ['check_no' => '1002', 'amount' => '200', 'due_date' => $first->due_date, 'installment_schedule_id' => $first->id],
        ])->assertCreated();

        $this->assertCount(2, $response->json('data.check_ids'));
        $rows = collect($response->json('data.schedule'))->keyBy('id');
        $this->assertSame('1001', $rows[$third->id]['checks'][0]['check_no']);
        $this->assertSame('1002', $rows[$first->id]['checks'][0]['check_no']);
        $this->assertSame([], $rows[$second->id]['checks']);
        $this->assertEquals(200, (float) DB::table('installment_schedule')->where('id', $third->id)->value('paid_amount'));
        $this->assertEquals(0, (float) DB::table('installment_schedule')->where('id', $second->id)->value('paid_amount'));
    }

    public function test_a_duplicate_check_rejects_the_whole_batch(): void
    {
        [$first, $second] = $this->installments();
        $this->batch([['check_no' => '2001', 'amount' => '200', 'due_date' => $first->due_date, 'installment_schedule_id' => $first->id]])->assertCreated();

        $this->batch([
            ['check_no' => '2002', 'amount' => '200', 'due_date' => $second->due_date, 'installment_schedule_id' => $second->id],
            ['check_no' => '2001', 'amount' => '200', 'due_date' => $second->due_date],
        ])->assertStatus(409)->assertJsonPath('code', 'CHECK_DUPLICATE');

        $this->assertSame(1, DB::table('checks')->count());
        $this->assertEquals(0, (float) DB::table('installment_schedule')->where('id', $second->id)->value('paid_amount'));
    }

    public function test_a_replacement_check_pays_the_same_installment_as_the_bounced_one(): void
    {
        [$first, , $third] = $this->installments();
        $id = $this->batch([['check_no' => '3001', 'amount' => '200', 'due_date' => '2026-01-05', 'installment_schedule_id' => $third->id]])->json('data.check_ids.0');
        app(ManageCheck::class)->deposit(['check_ids' => [$id], 'bank_account_id' => $this->bank, 'document_date' => '2026-01-06', 'reason' => 'إيداع الشيك'], $this->owner->id);
        app(ManageCheck::class)->transition($id, 'bounce', ['document_date' => '2026-01-07', 'reason' => 'رصيد غير كاف'], $this->owner->id);
        $this->assertEquals(0, (float) DB::table('installment_schedule')->where('id', $third->id)->value('paid_amount'));

        app(ManageCheck::class)->replace($id, ['customer_id' => $this->customer, 'check_no' => '3002', 'bank_name' => 'بنك', 'payer_name' => 'عميل الشيك', 'account_reference' => 'ACC-77',
            'currency' => 'ILS', 'amount' => '200', 'issue_date' => '2026-01-08', 'received_date' => '2026-01-08', 'due_date' => '2026-02-08'], $this->owner->id);

        $this->assertEquals(200, (float) DB::table('installment_schedule')->where('id', $third->id)->value('paid_amount'));
        $this->assertEquals(0, (float) DB::table('installment_schedule')->where('id', $first->id)->value('paid_amount'));
    }

    public function test_a_check_cannot_target_a_missing_installment(): void
    {
        $this->batch([['check_no' => '4001', 'amount' => '200', 'due_date' => '2026-02-05', 'installment_schedule_id' => 999999]])->assertUnprocessable();
    }
}
