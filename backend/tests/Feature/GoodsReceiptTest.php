<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Role;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Purchasing\Actions\PostGoodsReceipt;
use App\Domains\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domains\Purchasing\Actions\SaveGoodsReceipt;
use App\Domains\Purchasing\Actions\SavePurchaseOrder;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Domains\Purchasing\Models\GoodsReceipt;
use App\Domains\Purchasing\Models\PurchaseOrder;
use App\Domains\StoreSetup\Models\ExchangeRate;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    private int $supplier;

    private int $warehouse;

    private int $damaged;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($this->owner);
        app(CreateFiscalYear::class)->execute(['name' => 'GR tests', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->supplier = app(SaveSupplier::class)->execute(['code' => 'GR-SUP', 'legal_name' => 'مورد اختبار استلام', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 30, 'credit_limit' => '10000', 'active' => true], $this->owner->id)->id;
        $this->product = app(SaveProduct::class)->execute(['sku' => 'GR-PROD', 'name_ar' => 'جهاز استلام', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => true, 'active' => true, 'cash_price' => '20', 'installment_price' => '25', 'minimum_price' => '15', 'reorder_level' => '1', 'barcodes' => []], $this->owner->id);
        $this->warehouse = StockLocation::where('code', 'WAREHOUSE')->value('id');
        $this->damaged = StockLocation::where('code', 'DAMAGED')->value('id');
    }

    private function order(string $qty = '3', string $price = '100.0001', string $discount = '0.0001'): PurchaseOrder
    {
        DB::table('approval_policies')->where('key', 'purchase_order')->update(['threshold' => '999999']);
        $doc = app(SavePurchaseOrder::class)->execute(['supplier_id' => $this->supplier, 'document_date' => '2026-08-01', 'currency' => 'ILS', 'lines' => [['product_id' => $this->product->id, 'quantity' => $qty, 'unit_price' => $price, 'discount_amount' => $discount, 'tax_code_id' => null, 'tax_inclusive' => false]]], $this->owner->id);
        app(PurchaseOrderWorkflow::class)->submit($doc->id, 1, $this->owner->id);

        return app(PurchaseOrderWorkflow::class)->issue($doc->id, 1, $this->owner->id);
    }

    private function data(?PurchaseOrder $order, array $serials, array $extra = []): array
    {
        return ['supplier_id' => $this->supplier, 'purchase_order_id' => $order?->id, 'document_date' => '2026-08-02', 'delivery_reference' => 'DELIVERY-TEST', 'currency' => 'ILS', 'lines' => [['product_id' => $this->product->id, 'location_id' => $this->warehouse, 'condition' => 'new', 'quantity' => (string) count($serials), 'serials' => $serials, ...($order ? ['purchase_order_line_id' => $order->lines[0]->id] : ['unit_cost' => '100.0001'])]], ...$extra];
    }

    private function draft(array $data): GoodsReceipt
    {
        return app(SaveGoodsReceipt::class)->execute($data, $this->owner->id);
    }

    private function postReceipt(GoodsReceipt $doc): GoodsReceipt
    {
        return app(PostGoodsReceipt::class)->execute($doc->id, $doc->version, $this->owner->id);
    }

    private function gl(string $code): string
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', $code)->selectRaw('COALESCE(SUM(l.debit-l.credit),0) AS balance')->first()->balance;
    }

    public function test_partial_receipts_capture_condition_serials_and_reconcile_po_inventory_and_grni(): void
    {
        $order = $this->order();
        $first = $this->postReceipt($this->draft($this->data($order, ['RECEIVED-1'])));
        $this->assertSame('100.0001', $first->foreign_total);
        $data = $this->data($order, ['RECEIVED-2', 'RECEIVED-3']);
        $data['lines'][0]['condition'] = 'damaged';
        $data['lines'][0]['condition_notes'] = 'ضرر موثق عند التسليم';
        $data['lines'][0]['location_id'] = $this->damaged;
        $second = $this->postReceipt($this->draft($data));
        $this->postReceipt($second);
        $this->assertSame('200.0001', $second->foreign_total);
        $this->assertSame('300.0002', $this->gl('1300'));
        $this->assertSame('-300.0002', $this->gl('2300'));
        $this->assertSame('0.0000', $this->gl('2100'));
        $this->assertDatabaseHas('serial_numbers', ['serial_no' => 'RECEIVED-2', 'status' => 'damaged', 'current_location_id' => $this->damaged]);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseCount('serial_movements', 3);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->getJson('/api/v1/purchase-orders/'.$order->id)->assertOk()->assertJsonPath('data.lines.0.received_quantity', '3.0000')->assertJsonPath('data.lines.0.remaining_quantity', '0.0000');
        $this->getJson('/api/v1/inventory/balances?location_id='.$this->damaged)->assertOk()->assertJsonPath('data.0.sellable_quantity', '0.0000');
    }

    public function test_another_posted_receipt_invalidates_excess_quantity_in_an_existing_draft(): void
    {
        $order = $this->order();
        $one = $this->draft($this->data($order, ['ONE-1', 'ONE-2']));
        $two = $this->draft($this->data($order, ['TWO-1', 'TWO-2']));
        $this->postReceipt($one);
        try {
            $this->postReceipt($two);
            $this->fail('PO over-received');
        } catch (BusinessException $e) {
            $this->assertSame('PURCHASE_ORDER_OVER_RECEIPT', $e->errorCode);
        }
        $this->assertDatabaseMissing('serial_numbers', ['serial_no' => 'TWO-1']);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame('draft', $two->fresh()->status);
    }

    public function test_tiny_partial_values_use_cumulative_rounding_without_negative_final_receipt(): void
    {
        $order = $this->order('4', '0.0001', '0.0002');
        $values = [];
        foreach (range(1, 4) as $i) {
            $values[] = $this->postReceipt($this->draft($this->data($order, ['TINY-'.$i])))->foreign_total;
        }
        $this->assertSame(['0.0001', '0.0000', '0.0001', '0.0000'], $values);
        $this->assertSame('0.0002', $this->gl('1300'));
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_direct_receipt_permission_and_http_idempotency(): void
    {
        $data = $this->data(null, ['DIRECT-1']);
        $first = $this->postJson('/api/v1/goods-receipts', $data, ['Idempotency-Key' => 'direct-receipt-test'])->assertCreated();
        $id = $first->json('data.id');
        $this->postJson('/api/v1/goods-receipts', $data, ['Idempotency-Key' => 'direct-receipt-test'])->assertCreated()->assertJsonPath('data.id', $id);
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'inventory')->firstOrFail());
        $this->actingAs($user);
        $this->postJson('/api/v1/goods-receipts', $data, ['Idempotency-Key' => 'direct-forbidden'])->assertForbidden();
        $this->postJson('/api/v1/goods-receipts/'.$id.'/post', ['version' => 1], ['Idempotency-Key' => 'direct-post-denied'])->assertForbidden();
        $this->actingAs($this->owner);
        foreach (range(1, 2) as $i) {
            $this->postJson('/api/v1/goods-receipts/'.$id.'/post', ['version' => 1], ['Idempotency-Key' => 'direct-post-retry'])->assertOk()->assertJsonPath('data.document_no', 'GR/2026/000001');
        }
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_foreign_receipt_preserves_original_amount_and_rate_in_document_and_gl(): void
    {
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-08-01', 'rate_to_base' => '3.50000001', 'source' => 'GR test rate', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $draft = $this->draft($this->data(null, ['FX-1'], ['currency' => 'USD']));
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-08-02', 'rate_to_base' => '4', 'source' => 'new rate', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $doc = $this->postReceipt($draft);
        $this->assertSame('350.0004', $doc->base_total);
        $this->assertDatabaseHas('journal_entries', ['id' => $doc->posted_journal_entry_id, 'currency' => 'USD', 'exchange_rate' => '3.50000001', 'exchange_rate_source' => 'GR test rate']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $doc->posted_journal_entry_id, 'debit' => '350.0004', 'foreign_amount' => '100.0001', 'currency' => 'USD', 'exchange_rate' => '3.50000001']);
        $this->assertSame('350.0004', $this->gl('1300'));
    }

    public function test_locked_period_or_failed_gl_rolls_back_all_receipt_effects(): void
    {
        $doc = $this->draft($this->data(null, ['ROLLBACK-GR']));
        DB::table('accounting_periods')->where('starts_on', '2026-08-01')->update(['status' => 'locked']);
        try {
            $this->postReceipt($doc);
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('PERIOD_NOT_OPEN', $e->errorCode);
        }
        DB::table('accounting_periods')->where('starts_on', '2026-08-01')->update(['status' => 'open']);
        DB::table('accounts')->where('code', '2300')->update(['active' => false]);
        try {
            $this->postReceipt($doc);
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('ACCOUNT_INACTIVE', $e->errorCode);
        }
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('serial_numbers', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('document_sequence_counters', 0);
    }

    public function test_bad_condition_duplicate_serial_and_immutable_posted_receipt_are_rejected(): void
    {
        $data = $this->data(null, ['BAD-CONDITION']);
        $data['lines'][0]['condition'] = 'open_box';
        $data['lines'][0]['condition_notes'] = 'مفتوح';
        $this->postJson('/api/v1/goods-receipts', $data, ['Idempotency-Key' => 'bad-condition-location'])->assertUnprocessable()->assertJsonPath('code', 'RECEIPT_CONDITION_LOCATION');
        $posted = $this->postReceipt($this->draft($this->data(null, ['UNIQUE-GR'])));
        $duplicate = $this->draft($this->data(null, ['UNIQUE-GR']));
        try {
            $this->postReceipt($duplicate);
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('SERIAL_ALREADY_EXISTS', $e->errorCode);
        }
        $this->putJson('/api/v1/goods-receipts/'.$posted->id, $this->data(null, ['UNIQUE-GR'], ['version' => 1]))->assertConflict();
        $this->deleteJson('/api/v1/goods-receipts/'.$posted->id)->assertUnprocessable()->assertJsonPath('code', 'RECEIPT_DELETE_FORBIDDEN');
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'goods_receipt', 'entity_id' => $posted->id, 'action' => 'purchasing.receipt_deletion_rejected']);
        try {
            DB::table('goods_receipt_lines')->where('goods_receipt_id', $posted->id)->update(['quantity' => '99']);
            $this->fail();
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertDatabaseCount('inventory_movements', 1);
    }
}
