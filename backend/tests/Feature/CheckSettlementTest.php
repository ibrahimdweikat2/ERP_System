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

class CheckSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $customer;

    private int $invoice;

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
        $sale = app(SaveSalesInvoice::class)->execute(['customer_id' => $this->customer, 'document_date' => '2026-01-05', 'due_date' => '2026-03-05', 'currency' => 'ILS', 'sale_mode' => 'credit',
            'checkout' => ['amount' => '0', 'method' => 'cash', 'cashbox_id' => $this->cashbox, 'bank_account_id' => null],
            'lines' => [['product_id' => $product, 'location_id' => $warehouse, 'quantity' => '1', 'unit_price' => '1000', 'discount_amount' => '0', 'tax_code_id' => $tax, 'tax_inclusive' => true, 'serials' => []]]], $this->owner->id);
        app(PostSalesInvoice::class)->execute($sale->id, $this->owner->id);
        $this->invoice = $sale->id;
    }

    /** A check that paid the invoice, then bounced. */
    private function bouncedCheck(string $number = '9001', string $amount = '400'): object
    {
        $check = app(ManageCheck::class)->receive(['customer_id' => $this->customer, 'check_no' => $number, 'bank_name' => 'بنك', 'bank_branch' => 'رئيسي', 'payer_name' => 'عميل الشيك', 'account_reference' => 'ACC-1',
            'currency' => 'ILS', 'amount' => $amount, 'issue_date' => '2026-02-01', 'due_date' => '2026-02-01', 'received_date' => '2026-02-01', 'allocations' => [['sales_invoice_id' => $this->invoice, 'amount' => $amount]]], $this->owner->id);
        app(ManageCheck::class)->deposit(['check_ids' => [$check->id], 'bank_account_id' => $this->bank, 'document_date' => '2026-02-02', 'reason' => 'إيداع الشيك'], $this->owner->id);

        return app(ManageCheck::class)->transition($check->id, 'bounce', ['document_date' => '2026-02-05', 'reason' => 'رصيد غير كاف'], $this->owner->id);
    }

    private function receipt(string $date, string $amount, ?int $invoice = null, string $method = 'cash'): int
    {
        $payment = app(ReceiveCustomerPayment::class)->save(['customer_id' => $this->customer, 'document_date' => $date, 'currency' => 'ILS', 'amount' => $amount, 'method' => $method,
            'cashbox_id' => $this->cashbox, 'bank_account_id' => $method === 'bank' ? $this->bank : null, 'allocations' => [['sales_invoice_id' => $invoice ?? $this->invoice, 'amount' => $amount]]], $this->owner->id);
        app(ReceiveCustomerPayment::class)->post($payment->id, $this->owner->id);

        return $payment->id;
    }

    /** Each call is a distinct user action, so it carries its own idempotency key. */
    private function settle(int $check, array $body, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson("/api/v1/checks/{$check}/settle-existing", $body);
    }

    private function journalCount(): int
    {
        return DB::table('journal_entries')->count();
    }

    public function test_bounced_check_is_closed_against_an_existing_receipt_without_a_second_collection(): void
    {
        $check = $this->bouncedCheck();
        $payment = $this->receipt('2026-02-10', '400');
        $this->getJson("/api/v1/checks/{$check->id}/settlement-payments")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $payment)->assertJsonPath('data.0.covered_amount', '400.0000');

        $journals = $this->journalCount();
        $cash = DB::table('customer_payments')->count();
        $this->settle($check->id, ['customer_payment_id' => $payment, 'document_date' => '2026-02-11', 'reason' => 'العميل سدّد نقداً'])
            ->assertOk()->assertJsonPath('data.status', 'settled')->assertJsonPath('data.settlement_payment_id', $payment);
        $this->assertSame($journals, $this->journalCount(), 'Linking a receipt must not post a new journal.');
        $this->assertSame($cash, DB::table('customer_payments')->count(), 'Linking a receipt must not create a payment.');
        $this->assertSame('settled', DB::table('check_status_history')->where('check_id', $check->id)->orderByDesc('id')->value('to_status'));

        // Retrying the same link is harmless; the check is no longer a candidate for anything.
        $this->settle($check->id, ['customer_payment_id' => $payment, 'document_date' => '2026-02-11', 'reason' => 'العميل سدّد نقداً'])->assertOk();
        $this->getJson("/api/v1/checks/{$check->id}/settlement-payments")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_matching_receipts_can_settle_a_bounced_check(): void
    {
        $check = $this->bouncedCheck();
        $before = $this->receipt('2026-02-03', '400');      // before the bounce
        $short = $this->receipt('2026-02-10', '300');       // does not cover the check
        $candidates = collect($this->getJson("/api/v1/checks/{$check->id}/settlement-payments")->json('data'))->pluck('id');
        $this->assertNotContains($before, $candidates);
        $this->assertNotContains($short, $candidates);
        foreach ([$before, $short, $check->source_payment_id] as $payment) {
            $this->settle($check->id, ['customer_payment_id' => $payment, 'document_date' => '2026-02-12', 'reason' => 'محاولة غير صحيحة'])
                ->assertUnprocessable()->assertJsonPath('code', 'CHECK_SETTLEMENT_PAYMENT_INVALID');
        }
        $this->assertSame('bounced', DB::table('checks')->where('id', $check->id)->value('status'));

        $good = $this->receipt('2026-02-10', '100');
        $this->settle($check->id, ['customer_payment_id' => $good, 'document_date' => '2026-02-09', 'reason' => 'تاريخ يسبق السند'])
            ->assertUnprocessable();
    }

    public function test_one_receipt_cannot_settle_two_bounced_checks(): void
    {
        $first = $this->bouncedCheck('9001', '200');
        $second = $this->bouncedCheck('9002', '200');
        $payment = $this->receipt('2026-02-10', '400');
        $this->settle($first->id, ['customer_payment_id' => $payment, 'document_date' => '2026-02-11', 'reason' => 'تسوية الشيك الأول'])->assertOk();
        $this->settle($second->id, ['customer_payment_id' => $payment, 'document_date' => '2026-02-11', 'reason' => 'نفس السند مرة ثانية'])
            ->assertUnprocessable()->assertJsonPath('code', 'CHECK_SETTLEMENT_PAYMENT_INVALID');
        $this->assertSame('bounced', DB::table('checks')->where('id', $second->id)->value('status'));
    }

    public function test_settling_requires_the_check_replacement_permission(): void
    {
        $check = $this->bouncedCheck();
        $payment = $this->receipt('2026-02-10', '400');
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);
        $this->settle($check->id, ['customer_payment_id' => $payment, 'document_date' => '2026-02-11', 'reason' => 'بدون صلاحية'])->assertForbidden();
        $this->assertSame('bounced', DB::table('checks')->where('id', $check->id)->value('status'));
    }
}
