<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Accounting\Actions\PostJournal;
use App\Domains\Accounting\Actions\SaveManualJournal;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Journal;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Role;
use App\Domains\Inventory\Actions\SaveStockDocument;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Purchasing\Actions\SavePurchaseOrder;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeleteRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($this->owner);
        app(CreateFiscalYear::class)->execute(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    }

    private function journal(): int
    {
        return app(SaveManualJournal::class)->execute(['journal_id' => Journal::where('code', 'general')->value('id'), 'entry_date' => '2026-09-06', 'description' => 'قيد للحذف', 'currency' => 'ILS', 'lines' => [
            ['account_id' => Account::where('code', '1100')->value('id'), 'debit' => '10', 'credit' => '0'],
            ['account_id' => Account::where('code', '3100')->value('id'), 'debit' => '0', 'credit' => '10'],
        ]], $this->owner->id)->id;
    }

    private function product(): int
    {
        return app(SaveProduct::class)->execute(['sku' => 'DEL-P', 'name_ar' => 'منتج', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => false, 'active' => true,
            'cash_price' => '10', 'installment_price' => '12', 'minimum_price' => '9', 'reorder_level' => '0', 'barcodes' => []], $this->owner->id)->id;
    }

    public function test_draft_journal_is_deleted_with_its_lines_and_audited(): void
    {
        $id = $this->journal();

        $this->deleteJson("/api/v1/journal-entries/{$id}")->assertOk();

        $this->assertFalse(DB::table('journal_entries')->where('id', $id)->exists());
        $this->assertFalse(DB::table('journal_lines')->where('journal_entry_id', $id)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'documents.draft_deleted')->where('entity_type', 'journal_entry')->where('entity_id', $id)->exists());
    }

    public function test_posted_journal_is_still_retained(): void
    {
        $id = $this->journal();
        app(PostJournal::class)->execute($id, $this->owner->id);

        $this->deleteJson("/api/v1/journal-entries/{$id}")->assertUnprocessable()->assertJsonPath('code', 'JOURNAL_DELETE_FORBIDDEN');
        $this->assertTrue(DB::table('journal_entries')->where('id', $id)->exists());
    }

    public function test_draft_purchase_order_and_stock_adjustment_are_deleted(): void
    {
        $product = $this->product();
        $supplier = app(SaveSupplier::class)->execute(['code' => 'DEL-S', 'legal_name' => 'مورد', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 0, 'credit_limit' => '0', 'active' => true], $this->owner->id)->id;
        $order = app(SavePurchaseOrder::class)->execute(['supplier_id' => $supplier, 'document_date' => '2026-08-01', 'currency' => 'ILS',
            'lines' => [['product_id' => $product, 'quantity' => '1', 'unit_price' => '5', 'discount_amount' => '0', 'tax_code_id' => null, 'tax_inclusive' => false]]], $this->owner->id)->id;
        $adjustment = app(SaveStockDocument::class)->execute(StockDocumentType::Adjustment, ['document_date' => '2026-09-01', 'reason' => 'مسودة للحذف', 'location_id' => StockLocation::where('code', 'WAREHOUSE')->value('id'),
            'adjustment_kind' => 'opening', 'lines' => [['product_id' => $product, 'quantity' => '2', 'unit_cost' => '5', 'serials' => []]]], $this->owner->id)->id;

        $this->deleteJson("/api/v1/purchase-orders/{$order}")->assertOk();
        $this->deleteJson("/api/v1/stock-adjustments/{$adjustment}")->assertOk();

        $this->assertFalse(DB::table('purchase_order_lines')->where('purchase_order_id', $order)->exists());
        $this->assertFalse(DB::table('stock_adjustment_lines')->where('stock_adjustment_id', $adjustment)->exists());
        // Once its drafts are gone the product is unused again.
        $this->deleteJson("/api/v1/products/{$product}")->assertOk();
    }

    public function test_unused_list_records_are_deleted_and_used_ones_kept(): void
    {
        $unused = DB::table('brands')->insertGetId(['name_ar' => 'ماركة للحذف', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $used = DB::table('brands')->insertGetId(['name_ar' => 'ماركة مستخدمة', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->where('id', $this->product())->update(['brand_id' => $used]);

        $this->deleteJson("/api/v1/brands/{$unused}")->assertOk();
        $this->deleteJson("/api/v1/brands/{$used}")->assertStatus(409)->assertJsonPath('code', 'RECORD_IN_USE');

        $this->assertFalse(DB::table('brands')->where('id', $unused)->exists());
        $this->assertTrue(DB::table('brands')->where('id', $used)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'masters.deleted')->where('entity_type', 'brands')->where('entity_id', $unused)->exists());
    }

    public function test_accounts_with_children_and_system_records_are_kept(): void
    {
        $parent = DB::table('accounts')->where('code', '1100')->value('id');
        $custom = DB::table('accounts')->insertGetId(['code' => '1190', 'name_ar' => 'حساب فرعي', 'parent_id' => $parent, 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => false, 'allow_manual_posting' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $child = DB::table('accounts')->insertGetId(['code' => '1191', 'name_ar' => 'حساب ابن', 'parent_id' => $custom, 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => false, 'allow_manual_posting' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteJson("/api/v1/accounts/{$custom}")->assertStatus(409)->assertJsonPath('code', 'RECORD_IN_USE');
        $this->deleteJson("/api/v1/accounts/{$child}")->assertOk();
        $this->deleteJson("/api/v1/accounts/{$custom}")->assertOk();
        $this->deleteJson("/api/v1/accounts/{$parent}")->assertStatus(409)->assertJsonPath('code', 'SYSTEM_RECORD');
        $this->deleteJson('/api/v1/currencies/ILS')->assertStatus(409)->assertJsonPath('code', 'SYSTEM_RECORD');
        $this->deleteJson('/api/v1/units/'.Unit::where('code', 'piece')->value('id'))->assertStatus(409)->assertJsonPath('code', 'SYSTEM_RECORD');
        $this->deleteJson('/api/v1/journals/'.Journal::where('code', 'sales')->value('id'))->assertStatus(409)->assertJsonPath('code', 'SYSTEM_RECORD');
    }

    public function test_deleting_needs_the_management_permission(): void
    {
        $brand = DB::table('brands')->insertGetId(['name_ar' => 'ماركة', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);

        $this->deleteJson("/api/v1/brands/{$brand}")->assertForbidden();
        $this->deleteJson('/api/v1/journal-entries/'.$this->journal())->assertForbidden();
    }
}
