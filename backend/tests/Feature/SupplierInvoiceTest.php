<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Documents\Models\DocumentAttachment;
use App\Domains\Identity\Models\Role;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Purchasing\Actions\PostGoodsReceipt;
use App\Domains\Purchasing\Actions\PostSupplierInvoice;
use App\Domains\Purchasing\Actions\SaveGoodsReceipt;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Domains\Purchasing\Actions\SaveSupplierInvoice;
use App\Domains\Purchasing\Models\GoodsReceipt;
use App\Domains\Purchasing\Models\PurchasingInvoicePolicy;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Domains\StoreSetup\Models\ExchangeRate;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Domains\Tax\Models\TaxCode;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $supplier;

    private int $product;

    private int $tax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($this->owner);
        app(CreateFiscalYear::class)->execute(['name' => 'invoice test', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        StoreSetting::current()->update(['vat_registered' => true]);
        $this->supplier = app(SaveSupplier::class)->execute(['code' => 'PI-SUP', 'legal_name' => 'مورد فواتير اختبار', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 30, 'credit_limit' => '0', 'active' => true], $this->owner->id)->id;
        $this->product = app(SaveProduct::class)->execute(['sku' => 'PI-PROD', 'name_ar' => 'منتج فواتير', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => false, 'active' => true, 'cash_price' => '200', 'installment_price' => '220', 'minimum_price' => '150', 'reorder_level' => '1', 'barcodes' => []], $this->owner->id)->id;
        $this->tax = TaxCode::create(['code' => 'TEST10', 'name_ar' => 'ضريبة اختبار فقط', 'category' => 'standard', 'rate' => '10', 'effective_from' => '2026-01-01', 'input_account_id' => Account::where('code', '1400')->value('id'), 'output_account_id' => Account::where('code', '2200')->value('id')])->id;
    }

    private function receipt(string $qty = '3', string $cost = '100', string $currency = 'ILS'): GoodsReceipt
    {
        $receipt = app(SaveGoodsReceipt::class)->execute(['supplier_id' => $this->supplier, 'document_date' => '2026-08-01', 'currency' => $currency, 'delivery_reference' => 'PI-DELIVERY', 'lines' => [['product_id' => $this->product, 'location_id' => StockLocation::where('code', 'WAREHOUSE')->value('id'), 'condition' => 'new', 'quantity' => $qty, 'unit_cost' => $cost, 'serials' => []]]], $this->owner->id);

        return app(PostGoodsReceipt::class)->execute($receipt->id, 1, $this->owner->id);
    }

    private function data(GoodsReceipt $receipt, array $overrides = []): array
    {
        return ['supplier_id' => $this->supplier, 'supplier_invoice_no' => 'SUP-001', 'invoice_date' => '2026-08-01', 'posting_date' => '2026-09-01', 'due_date' => '2026-09-30', 'currency' => $receipt->currency, 'lines' => [['goods_receipt_line_id' => $receipt->lines[0]->id, 'quantity' => $receipt->lines[0]->quantity, 'unit_price' => '100', 'discount_amount' => '0', 'tax_code_id' => $this->tax, 'tax_inclusive' => false, 'tax_recoverable' => true]], ...$overrides];
    }

    private function draft(array $data): SupplierInvoice
    {
        return app(SaveSupplierInvoice::class)->execute($data, $this->owner->id);
    }

    private function postInvoice(SupplierInvoice $invoice): SupplierInvoice
    {
        return app(PostSupplierInvoice::class)->execute($invoice->id, $invoice->version, $this->owner->id);
    }

    private function gl(string $code): string
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)->selectRaw('COALESCE(SUM(l.debit-l.credit),0) AS balance')->first()->balance;
    }

    private function rejectPosting(SupplierInvoice $invoice, string $code): void
    {
        try {
            $this->postInvoice($invoice);
            $this->fail('Invalid invoice posted.');
        } catch (BusinessException $error) {
            $this->assertSame($code, $error->errorCode);
        }
    }

    public function test_inclusive_vat_clears_grni_and_reconciles_ap_without_changing_stock(): void
    {
        $receipt = $this->receipt();
        $data = $this->data($receipt);
        $data['lines'][0]['unit_price'] = '110';
        $data['lines'][0]['tax_inclusive'] = true;
        $invoice = $this->postInvoice($this->draft($data));
        $this->postInvoice($invoice);
        $this->assertSame('PI/2026/000001', $invoice->document_no);
        $this->assertSame('300.0000', $invoice->net_total);
        $this->assertSame('30.0000', $invoice->tax_total);
        $this->assertSame('0.0000', $this->gl('2300'));
        $this->assertSame('-330.0000', $this->gl('2100'));
        $this->assertSame('30.0000', $this->gl('1400'));
        $this->assertSame('300.0000', $this->gl('1300'));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('supplier_ledger_entries', 1);
        $this->assertDatabaseCount('tax_transactions', 1);
        $this->assertDatabaseHas('supplier_ledger_entries', ['source_id' => $invoice->id, 'foreign_amount' => '330.0000', 'base_amount' => '330.0000']);
        $this->assertDatabaseHas('tax_transactions', ['source_id' => $invoice->id, 'taxable_base' => '300.0000', 'tax_amount' => '30.0000', 'recoverable_base_amount' => '30.0000']);
        TaxCode::whereKey($this->tax)->update(['name_ar' => 'اسم جديد']);
        $this->assertSame('ضريبة اختبار فقط', $invoice->fresh('lines')->lines[0]->tax_snapshot['name_ar']);
    }

    public function test_foreign_invoice_separates_price_and_fx_variances_and_preserves_receipt_rate(): void
    {
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-08-01', 'rate_to_base' => '3.5', 'source' => 'receipt rate', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $receipt = $this->receipt('1', '100', 'USD');
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-09-01', 'rate_to_base' => '4', 'source' => 'invoice rate', 'created_by' => $this->owner->id, 'created_at' => now()]);
        PurchasingInvoicePolicy::current()->update(['price_variance_mode' => 'post_to_expense', 'price_variance_account_id' => Account::where('code', '6400')->value('id')]);
        $data = $this->data($receipt, ['variance_reason' => 'فرق سعر مثبت في فاتورة المورد']);
        $data['lines'][0]['unit_price'] = '110';
        $draft = $this->draft($data);
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-09-02', 'rate_to_base' => '5', 'source' => 'later rate', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $invoice = $this->postInvoice($draft);
        $this->assertSame('121.0000', $invoice->foreign_total);
        $this->assertSame('484.0000', $invoice->base_total);
        $this->assertSame('40.0000', $this->gl('6400'));
        $this->assertSame('50.0000', $this->gl('6500'));
        $this->assertSame('0.0000', $this->gl('2300'));
        $this->assertSame('350.0000', $this->gl('1300'));
        $this->assertSame('-484.0000', $this->gl('2100'));
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $invoice->posted_journal_entry_id, 'account_id' => Account::where('code', '2300')->value('id'), 'debit' => '350.0000', 'foreign_amount' => '100.0000', 'exchange_rate' => '3.50000000']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $invoice->posted_journal_entry_id, 'account_id' => Account::where('code', '6500')->value('id'), 'debit' => '50.0000', 'foreign_amount' => '0.0000']);
    }

    public function test_partial_matching_closes_exact_receipt_value_and_rejects_a_competing_draft(): void
    {
        $receipt = $this->receipt('3', '100.0001');
        $data = $this->data($receipt);
        $data['lines'][0]['quantity'] = '1';
        $data['lines'][0]['unit_price'] = '100.0001';
        $first = $this->postInvoice($this->draft($data));
        $data['supplier_invoice_no'] = 'SUP-002';
        $data['lines'][0]['quantity'] = '2';
        $second = $this->draft($data);
        $data['supplier_invoice_no'] = 'SUP-003';
        $competing = $this->draft($data);
        $second = $this->postInvoice($second);
        $this->rejectPosting($competing, 'RECEIPT_OVER_INVOICED');
        $this->assertSame('100.0001', $first->grni_total);
        $this->assertSame('200.0002', $second->grni_total);
        $this->assertSame('0.0000', $this->gl('2300'));
        $this->assertDatabaseCount('supplier_ledger_entries', 2);
        $this->getJson('/api/v1/purchasing/invoice-receipts?supplier_id='.$this->supplier.'&currency=ILS')->assertOk()->assertJsonPath('data.0.lines.0.uninvoiced_quantity', '0.0000');
    }

    public function test_price_policy_period_and_account_failure_leave_no_partial_invoice_posting(): void
    {
        $data = $this->data($this->receipt('1'));
        $data['lines'][0]['unit_price'] = '101';
        $invoice = $this->draft($data);
        $this->rejectPosting($invoice, 'PURCHASE_PRICE_VARIANCE_BLOCKED');
        $data['version'] = 1;
        $data['lines'][0]['unit_price'] = '100';
        $invoice = app(SaveSupplierInvoice::class)->execute($data, $this->owner->id, $invoice->id);
        DB::table('accounting_periods')->where('starts_on', '2026-09-01')->update(['status' => 'locked']);
        $this->rejectPosting($invoice, 'PERIOD_NOT_OPEN');
        DB::table('accounting_periods')->where('starts_on', '2026-09-01')->update(['status' => 'open']);
        Account::where('code', '2100')->update(['active' => false]);
        $this->rejectPosting($invoice, 'AP_ACCOUNT_INVALID');
        $this->assertDatabaseCount('supplier_ledger_entries', 0);
        $this->assertDatabaseCount('tax_transactions', 0);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_nonrecoverable_tax_requires_explicit_policy_and_recoverable_tax_requires_registration(): void
    {
        $data = $this->data($this->receipt('1'));
        StoreSetting::current()->update(['vat_registered' => false]);
        $invoice = $this->draft($data);
        $this->rejectPosting($invoice, 'INPUT_VAT_NOT_ELIGIBLE');
        $data['version'] = 1;
        $data['lines'][0]['tax_recoverable'] = false;
        $invoice = app(SaveSupplierInvoice::class)->execute($data, $this->owner->id, $invoice->id);
        $this->rejectPosting($invoice, 'NONRECOVERABLE_TAX_BLOCKED');
        PurchasingInvoicePolicy::current()->update(['version' => 2, 'nonrecoverable_tax_mode' => 'expense', 'nonrecoverable_tax_account_id' => Account::where('code', '6400')->value('id')]);
        $this->rejectPosting($invoice, 'INVOICE_POLICY_CHANGED');
        $data['version'] = 2;
        $invoice = app(SaveSupplierInvoice::class)->execute($data, $this->owner->id, $invoice->id);
        $this->postInvoice($invoice);
        $this->assertSame('10.0000', $this->gl('6400'));
        $this->assertSame('0.0000', $this->gl('1400'));
        $this->assertDatabaseHas('tax_transactions', ['source_id' => $invoice->id, 'recoverable_base_amount' => '0.0000', 'base_tax_amount' => '10.0000']);
    }

    public function test_http_duplicate_invoice_permissions_and_immutable_posted_records(): void
    {
        $data = $this->data($this->receipt('1'));
        $id = $this->postJson('/api/v1/supplier-invoices', $data, ['Idempotency-Key' => 'invoice-create-first'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/supplier-invoices', $data, ['Idempotency-Key' => 'invoice-create-first'])->assertCreated()->assertJsonPath('data.id', $id);
        $data['supplier_invoice_no'] = ' sup-001 ';
        $this->postJson('/api/v1/supplier-invoices', $data, ['Idempotency-Key' => 'invoice-create-duplicate'])->assertConflict()->assertJsonPath('code', 'DUPLICATE_SUPPLIER_INVOICE');
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'inventory')->firstOrFail());
        $this->actingAs($user);
        $this->postJson('/api/v1/supplier-invoices/'.$id.'/post', ['version' => 1], ['Idempotency-Key' => 'invoice-forbidden'])->assertForbidden();
        $this->actingAs($this->owner);
        $this->postJson('/api/v1/supplier-invoices/'.$id.'/post', ['version' => 1], ['Idempotency-Key' => 'invoice-post-first'])->assertOk();
        $this->deleteJson('/api/v1/supplier-invoices/'.$id)->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'purchasing.invoice_deletion_rejected', 'entity_id' => (string) $id]);
        foreach (['supplier_invoices', 'supplier_invoice_lines', 'supplier_ledger_entries', 'tax_transactions'] as $table) {
            try {
                DB::table($table)->where('id', DB::table($table)->value('id'))->delete();
                $this->fail('Posted source deleted: '.$table);
            } catch (QueryException $error) {
                $this->assertSame('45000', $error->errorInfo[0]);
            }
        }
    }

    public function test_private_attachment_is_required_when_configured_and_download_checks_parent_authority(): void
    {
        Storage::fake('documents');
        PurchasingInvoicePolicy::current()->update(['require_attachment' => true]);
        $invoice = $this->draft($this->data($this->receipt('1')));
        $this->rejectPosting($invoice, 'INVOICE_ATTACHMENT_REQUIRED');
        $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF";
        $response = $this->post('/api/v1/supplier-invoices/'.$invoice->id.'/attachments', ['file' => UploadedFile::fake()->createWithContent('scan.pdf', $content)], ['Accept' => 'application/json'])->assertCreated();
        $attachmentId = $response->json('data.id');
        $response->assertJsonMissingPath('data.stored_path');
        $this->post('/api/v1/supplier-invoices/'.$invoice->id.'/attachments', ['file' => UploadedFile::fake()->createWithContent('scan.pdf', $content)], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.id', $attachmentId);
        $this->assertDatabaseCount('document_attachments', 1);
        $this->assertCount(1, Storage::disk('documents')->allFiles());
        $this->postInvoice($invoice);
        $path = '/api/v1/supplier-invoices/'.$invoice->id.'/attachments/'.$attachmentId.'/download';
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->draft($this->data($this->receipt('1'), ['supplier_invoice_no' => 'ANOTHER']));
        $this->get('/api/v1/supplier-invoices/'.$other->id.'/attachments/'.$attachmentId.'/download')->assertNotFound();
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);
        $this->getJson($path)->assertForbidden();
        $this->actingAs($this->owner)->post('/api/v1/supplier-invoices/'.$invoice->id.'/attachments',['file' => UploadedFile::fake()->createWithContent('unsafe.html','<script>alert(1)</script>')],['Accept' => 'application/json'])->assertUnprocessable();
        $attachment = DocumentAttachment::findOrFail($attachmentId);
        Storage::disk('documents')->assertExists($attachment->stored_path);
    }
}
