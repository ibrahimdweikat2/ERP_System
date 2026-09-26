<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Approvals\Actions\DecideInventoryApproval;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Role;
use App\Domains\Inventory\Actions\PostStockDocument;
use App\Domains\Inventory\Actions\SaveStockDocument;
use App\Domains\Inventory\Actions\SubmitStockDocument;
use App\Domains\Inventory\Enums\StockDocumentType as Type;
use App\Domains\Inventory\Models\InventoryBalance;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $bulk;

    private Product $serial;

    private int $warehouse;

    private int $showroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        // Full permissions without the owner's automatic approval, so the workflow is exercised.
        $this->owner->roles()->attach($this->managerRole());
        $this->actingAs($this->owner);
        app(CreateFiscalYear::class)->execute(['name' => 'Inventory test', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->warehouse = StockLocation::where('code', 'WAREHOUSE')->value('id');
        $this->showroom = StockLocation::where('code', 'SHOWROOM')->value('id');
        $data = ['sku' => 'BULK-1', 'name_ar' => 'منتج كمية', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => false, 'active' => true, 'standard_cost' => '1', 'cash_price' => '20', 'installment_price' => '30', 'minimum_price' => '10', 'reorder_level' => '2', 'barcodes' => []];
        $this->bulk = app(SaveProduct::class)->execute($data, $this->owner->id);
        $this->serial = app(SaveProduct::class)->execute([...$data, 'sku' => 'SER-1', 'name_ar' => 'جهاز مسلسل', 'serial_tracked' => true], $this->owner->id);
    }

    private function draft(Type $type, array $lines, array $extra = []): InventoryDocument
    {
        return app(SaveStockDocument::class)->execute($type, ['document_date' => '2026-09-01', 'reason' => 'اختبار حركات مخزون موثق', 'location_id' => $this->warehouse, 'lines' => $lines, ...$extra], $this->owner->id);
    }

    private function line(Product $product, string $quantity, ?string $cost = null, array $serials = []): array
    {
        return ['product_id' => $product->id, 'quantity' => $quantity, 'unit_cost' => $cost, 'serials' => $serials];
    }

    private function approve(InventoryDocument $doc, Type $type = Type::Adjustment): InventoryDocument
    {
        $doc = app(SubmitStockDocument::class)->execute($type, $doc->id, $doc->version, $this->owner->id);
        if ($doc->approval_id) {
            app(DecideInventoryApproval::class)->execute($doc->approval_id, 'approved', 'الموافقة بعد مراجعة المخزون', $this->owner->id);
        }

        return $doc->fresh();
    }

    private function postDocument(InventoryDocument $doc, Type $type = Type::Adjustment): InventoryDocument
    {
        return app(PostStockDocument::class)->execute($type, $doc->id, $doc->version, $this->owner->id);
    }

    private function opening(Product $p, string $qty, string $cost, array $serials = []): InventoryDocument
    {
        return $this->postDocument($this->approve($this->draft(Type::Adjustment, [$this->line($p, $qty, $cost, $serials)], ['adjustment_kind' => 'opening'])));
    }

    private function balance(Product $p, int $location): InventoryBalance
    {
        return InventoryBalance::where('product_id', $p->id)->where('location_id', $location)->firstOrFail();
    }

    private function inventoryGl(): string
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.code', '1300')->selectRaw('COALESCE(SUM(l.debit-l.credit),0) value')->first()->value;
    }

    public function test_opening_stock_requires_approval_and_posts_one_exact_ledger_atomically(): void
    {
        $doc = $this->draft(Type::Adjustment, [$this->line($this->bulk, '3', '1.3333')], ['adjustment_kind' => 'opening']);
        try {
            $this->postDocument($doc);
            $this->fail('Unapproved stock posted');
        } catch (BusinessException $e) {
            $this->assertSame('STOCK_DOCUMENT_NOT_APPROVED', $e->errorCode);
        }
        $this->assertDatabaseCount('inventory_movements', 0);
        $doc = $this->postDocument($this->approve($doc));
        $this->assertSame('3.9999', $this->balance($this->bulk, $this->warehouse)->inventory_value);
        $this->assertSame('3.9999', $this->inventoryGl());
        $this->postDocument($doc);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->assertSame('SA/2026/000001', $doc->document_no);
    }

    public function test_weighted_average_transfers_loss_and_final_count_reconcile_exactly(): void
    {
        $this->opening($this->bulk, '3', '1.3333');
        $this->postDocument($this->approve($this->draft(Type::Adjustment, [$this->line($this->bulk, '2', '2.0001')], ['adjustment_kind' => 'gain'])));
        $this->assertSame('8.0001', $this->balance($this->bulk, $this->warehouse)->inventory_value);
        $transfer = $this->draft(Type::Transfer, [$this->line($this->bulk, '2')], ['destination_id' => $this->showroom]);
        $this->postDocument($transfer, Type::Transfer);
        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertSame('4.8001', $this->balance($this->bulk, $this->warehouse)->inventory_value);
        $this->assertSame('3.2000', $this->balance($this->bulk, $this->showroom)->inventory_value);
        $this->postDocument($this->approve($this->draft(Type::Adjustment, [$this->line($this->bulk, '1')], ['adjustment_kind' => 'loss'])));
        $count = $this->draft(Type::Count, [['product_id' => $this->bulk->id, 'counted_quantity' => '0', 'counted_serials' => []]]);
        $this->postDocument($this->approve($count, Type::Count), Type::Count);
        $this->assertSame('0.0000', $this->balance($this->bulk, $this->warehouse)->inventory_value);
        $this->assertSame('3.2000', $this->inventoryGl());
        $movementNet = DB::table('inventory_movements')->selectRaw("SUM(IF(direction='in',total_cost,-total_cost)) value")->first()->value;
        $this->assertSame($this->inventoryGl(), $movementNet);
        $this->assertSame($movementNet, DB::table('inventory_balances')->sum('inventory_value'));
    }

    public function test_serial_transfer_keeps_acquisition_cost_and_tracks_disposition(): void
    {
        $this->opening($this->serial, '2', '100.0001', ['SER001', 'SER002']);
        $doc = $this->draft(Type::Transfer, [$this->line($this->serial, '1', null, ['SER001'])], ['destination_id' => $this->showroom]);
        $this->postDocument($doc, Type::Transfer);
        $this->assertDatabaseHas('serial_numbers', ['serial_no' => 'SER001', 'current_location_id' => $this->showroom, 'status' => 'in_stock', 'acquisition_cost' => '100.0001']);
        $damaged = StockLocation::where('code', 'DAMAGED')->value('id');
        $doc = $this->draft(Type::Transfer, [$this->line($this->serial, '1', null, ['SER001'])], ['location_id' => $this->showroom, 'destination_id' => $damaged]);
        $this->postDocument($doc, Type::Transfer);
        $this->assertDatabaseHas('serial_numbers', ['serial_no' => 'SER001', 'status' => 'damaged', 'current_location_id' => $damaged]);
        $this->getJson('/api/v1/inventory/serials?eligible=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.serial_no', 'SER002');
        $this->assertDatabaseCount('serial_movements', 4);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_count_replaces_missing_serial_with_new_serial_without_hiding_value_changes(): void
    {
        $this->opening($this->serial, '2', '100', ['C1', 'C2']);
        $count = $this->draft(Type::Count, [['product_id' => $this->serial->id, 'counted_quantity' => '2', 'counted_serials' => ['C2', 'C3']]]);
        $doc = $this->postDocument($this->approve($count, Type::Count), Type::Count);
        $this->assertSame('200.0000', $doc->gross_value);
        $this->assertDatabaseHas('serial_numbers', ['serial_no' => 'C1', 'status' => 'retired', 'current_location_id' => null]);
        $this->assertDatabaseHas('serial_numbers', ['serial_no' => 'C3', 'status' => 'in_stock']);
        $this->assertSame('200.0000', $this->inventoryGl());
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_count_snapshot_rejects_intervening_stock_movement(): void
    {
        $this->opening($this->bulk, '5', '10');
        $count = $this->draft(Type::Count, [['product_id' => $this->bulk->id, 'counted_quantity' => '4', 'counted_serials' => []]]);
        $this->postDocument($this->draft(Type::Transfer, [$this->line($this->bulk, '1')], ['destination_id' => $this->showroom]), Type::Transfer);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('تحرك المخزون');
        $this->approve($count, Type::Count);
    }

    public function test_editing_approved_document_invalidates_approval_and_checks_version(): void
    {
        $doc = $this->approve($this->draft(Type::Adjustment, [$this->line($this->bulk, '2', '10')], ['adjustment_kind' => 'opening']));
        $oldApproval = $doc->approval_id;
        $d = ['version' => 1, 'document_date' => $doc->document_date, 'location_id' => $this->warehouse, 'reason' => 'تعديل الكمية بعد اعتماد المستند', 'adjustment_kind' => 'opening', 'lines' => [$this->line($this->bulk, '3', '10')]];
        $updated = app(SaveStockDocument::class)->execute(Type::Adjustment, $d, $this->owner->id, $doc->id);
        $this->assertSame('draft', $updated->status);
        $this->assertNull($updated->approval_id);
        $this->assertDatabaseHas('approvals', ['id' => $oldApproval, 'status' => 'superseded']);
        $this->expectException(BusinessException::class);
        $this->postDocument($doc);
    }

    public function test_cost_change_after_approval_requires_new_approval(): void
    {
        $this->opening($this->bulk, '2', '10');
        $loss = $this->approve($this->draft(Type::Adjustment, [$this->line($this->bulk, '1')], ['adjustment_kind' => 'loss']));
        $this->postDocument($this->approve($this->draft(Type::Adjustment, [$this->line($this->bulk, '2', '20')], ['adjustment_kind' => 'gain'])));
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('تغير المستند');
        $this->postDocument($loss);
    }

    public function test_configured_separation_rejects_requester_approval(): void
    {
        DB::table('approval_policies')->update(['segregate_requester' => true]);
        $doc = $this->draft(Type::Adjustment, [$this->line($this->bulk, '1', '10')], ['adjustment_kind' => 'opening']);
        $doc = app(SubmitStockDocument::class)->execute(Type::Adjustment, $doc->id, 1, $this->owner->id);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('فصل المهام');
        app(DecideInventoryApproval::class)->execute($doc->approval_id, 'approved', 'طلب اعتماد ذاتي', $this->owner->id);
    }

    public function test_locked_period_rolls_back_stock_and_number_allocation(): void
    {
        $doc = $this->approve($this->draft(Type::Adjustment, [$this->line($this->serial, '1', '10', ['LOCK-1'])], ['adjustment_kind' => 'opening']));
        DB::table('accounting_periods')->where('starts_on', '2026-09-01')->update(['status' => 'locked']);
        try {
            $this->postDocument($doc);
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('PERIOD_NOT_OPEN', $e->errorCode);
        }
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('serial_numbers', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('document_sequence_counters', 0);
    }

    public function test_duplicate_serial_and_negative_stock_fail_without_partial_effects(): void
    {
        $this->opening($this->serial, '1', '10', ['DUP-1']);
        try {
            $this->approve($this->draft(Type::Adjustment, [$this->line($this->serial, '1', '20', ['DUP-1'])], ['adjustment_kind' => 'gain']));
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('SERIAL_ALREADY_EXISTS', $e->errorCode);
        }
        try {
            $this->postDocument($this->draft(Type::Transfer, [$this->line($this->serial, '2', null, ['DUP-1', 'MISSING'])], ['destination_id' => $this->showroom]), Type::Transfer);
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('INSUFFICIENT_STOCK', $e->errorCode);
        }
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame('10.0000', $this->inventoryGl());
    }

    public function test_posted_movement_and_document_are_immutable_in_mysql(): void
    {
        $doc = $this->opening($this->bulk, '2', '10');
        try {
            DB::table('inventory_movements')->update(['quantity' => '5']);
            $this->fail();
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        try {
            DB::table('stock_adjustment_lines')->where('stock_adjustment_id', $doc->id)->delete();
            $this->fail();
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->deleteJson('/api/v1/stock-adjustments/'.$doc->id)->assertUnprocessable()->assertJsonPath('code', 'STOCK_DOCUMENT_DELETE_FORBIDDEN');
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.deletion_rejected', 'entity_id' => $doc->id]);
    }

    public function test_http_normalization_idempotency_and_cost_permissions(): void
    {
        $data = ['document_date' => '2026-09-01', 'reason' => 'افتتاحي عبر واجهة الخادم', 'location_id' => $this->warehouse, 'adjustment_kind' => 'opening', 'lines' => [$this->line($this->serial, '1', '12.3456', [' low 123 '])]];
        $response = $this->withHeader('Idempotency-Key', 'inventory-create-unique')->postJson('/api/v1/stock-adjustments', $data)->assertCreated()->assertJsonPath('data.lines.0.serials.0', 'LOW123');
        $this->withHeader('Idempotency-Key', 'inventory-create-unique')->postJson('/api/v1/stock-adjustments', $data)->assertCreated()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertDatabaseCount('stock_adjustments', 1);
        $doc = StockAdjustment::firstOrFail();
        $this->postDocument($this->approve($doc));
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);
        $this->getJson('/api/v1/inventory/balances')->assertOk()->assertJsonMissingPath('data.0.inventory_value')->assertJsonMissingPath('data.0.product.standard_cost');
        $this->getJson('/api/v1/inventory/serials')->assertOk()->assertJsonMissingPath('data.0.acquisition_cost');
        $this->getJson('/api/v1/inventory/movements')->assertOk()->assertJsonMissingPath('data.0.total_cost');
        $this->getJson('/api/v1/stock-adjustments/'.$doc->id)->assertOk()->assertJsonMissingPath('data.gross_value')->assertJsonMissingPath('data.lines.0.unit_cost')->assertJsonMissingPath('data.approval.payload_json');
        $this->postJson('/api/v1/stock-adjustments', $data)->assertForbidden();
    }

    public function test_gl_failure_rolls_back_stock_serials_and_permanent_numbers(): void
    {
        $doc = $this->approve($this->draft(Type::Adjustment, [$this->line($this->serial, '1', '10', ['ROLLBACK-1'])], ['adjustment_kind' => 'opening']));
        DB::table('accounts')->where('code', '1300')->update(['active' => false]);
        try {
            $this->postDocument($doc);
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('ACCOUNT_INACTIVE', $e->errorCode);
        }
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('serial_numbers', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('document_sequence_counters', 0);
        $this->assertSame('0.0000', $this->balance($this->serial, $this->warehouse)->qty_on_hand);
    }

    public function test_precision_and_tracking_cannot_change_after_stock_history(): void
    {
        $this->opening($this->bulk, '2', '10');
        $unit = Unit::firstOrFail();
        $this->putJson('/api/v1/units/'.$unit->id, ['name_ar' => $unit->name_ar, 'code' => $unit->code, 'decimal_places' => 2])->assertUnprocessable()->assertJsonPath('code', 'UNIT_PRECISION_IN_USE');
        $data = ['sku' => $this->bulk->sku, 'name_ar' => $this->bulk->name_ar, 'unit_id' => (string) $unit->id, 'serial_tracked' => false, 'active' => true, 'cash_price' => '20', 'installment_price' => '30', 'minimum_price' => '10', 'reorder_level' => '2', 'barcodes' => []];
        $this->putJson('/api/v1/products/'.$this->bulk->id, $data)->assertOk();
        $this->putJson('/api/v1/products/'.$this->bulk->id, [...$data, 'serial_tracked' => true])->assertUnprocessable()->assertJsonPath('code', 'PRODUCT_TRACKING_IN_USE');
    }

    public function test_operational_movement_prevents_another_opening_even_when_it_is_drafted_earlier(): void
    {
        $this->opening($this->bulk, '2', '10');
        $lateOpening = $this->draft(Type::Adjustment, [$this->line($this->bulk, '1', '10')], ['adjustment_kind' => 'opening']);
        $transfer = $this->postDocument($this->draft(Type::Transfer, [$this->line($this->bulk, '1')], ['destination_id' => $this->showroom]), Type::Transfer);
        $this->getJson('/api/v1/stock-transfers/'.$transfer->id)->assertOk()->assertJsonPath('data.destination.name_ar', 'صالة العرض');
        try {
            $this->approve($lateOpening);
            $this->fail('Operational stock accepted another opening');
        } catch (BusinessException $e) {
            $this->assertSame('OPENING_STOCK_ALREADY_USED', $e->errorCode);
        }
        $this->assertSame('20.0000', $this->inventoryGl());
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_reorder_includes_products_without_balances_and_ignores_quarantine(): void
    {
        $this->getJson('/api/v1/inventory/reorder')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.available', '0.0000');
        $this->opening($this->bulk, '5', '10');
        $this->getJson('/api/v1/inventory/reorder')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.sku', 'SER-1');
        $damaged = StockLocation::where('code', 'DAMAGED')->value('id');
        $this->postDocument($this->draft(Type::Transfer, [$this->line($this->bulk, '5')], ['destination_id' => $damaged]), Type::Transfer);
        $this->getJson('/api/v1/inventory/reorder')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/v1/inventory/balances?quarantine=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.sellable_quantity', '0.0000');
    }

    public function test_policy_change_rejects_pending_and_previously_automatic_approval(): void
    {
        $pending = app(SubmitStockDocument::class)->execute(Type::Adjustment, $this->draft(Type::Adjustment, [$this->line($this->bulk, '1', '10')], ['adjustment_kind' => 'opening'])->id, 1, $this->owner->id);
        DB::table('approval_policies')->update(['version' => 2, 'threshold' => '100']);
        try {
            app(DecideInventoryApproval::class)->execute($pending->approval_id, 'approved', 'مراجعة بعد تغيير السياسة', $this->owner->id);
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('APPROVAL_POLICY_CHANGED', $e->errorCode);
        }
        $gain = $this->approve($this->draft(Type::Adjustment, [$this->line($this->bulk, '1', '10')], ['adjustment_kind' => 'gain']));
        $this->assertNull($gain->approval_id);
        DB::table('approval_policies')->update(['version' => 3, 'threshold' => '0']);
        try {
            $this->postDocument($gain);
            $this->fail();
        } catch (BusinessException $e) {
            $this->assertSame('APPROVAL_PAYLOAD_CHANGED', $e->errorCode);
        }
        $this->assertDatabaseCount('inventory_movements', 0);
    }
}
