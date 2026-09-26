<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Role;
use App\Domains\Inventory\Actions\PostStockDocument;
use App\Domains\Inventory\Actions\SaveStockDocument;
use App\Domains\Inventory\Actions\SubmitStockDocument;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\Inventory\Models\StockLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpeningStockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $product;

    private int $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($this->owner);
        app(CreateFiscalYear::class)->execute(['name' => 'opening', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->product = app(SaveProduct::class)->execute(['sku' => 'lg-ref-730', 'name_ar' => 'ثلاجة', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => true, 'active' => true,
            'standard_cost' => '100', 'cash_price' => '150', 'installment_price' => '180', 'minimum_price' => '120', 'reorder_level' => '1', 'barcodes' => []], $this->owner->id)->id;
        $this->location = StockLocation::where('code', 'WAREHOUSE')->value('id');
    }

    private function opening(array $serials, int $actor): object
    {
        $data = ['document_date' => '2026-09-01', 'reason' => 'بضاعة موجودة قبل تشغيل النظام', 'location_id' => $this->location, 'adjustment_kind' => 'opening',
            'lines' => [['product_id' => $this->product, 'quantity' => (string) count($serials), 'unit_cost' => '100', 'serials' => $serials]]];
        $doc = app(SaveStockDocument::class)->execute(StockDocumentType::Adjustment, $data, $actor);

        return app(SubmitStockDocument::class)->execute(StockDocumentType::Adjustment, $doc->id, 1, $actor);
    }

    public function test_suggested_serials_fill_missing_units_and_skip_used_numbers(): void
    {
        $this->getJson("/api/v1/inventory/serials/suggest?product_id={$this->product}&count=2")->assertOk()
            ->assertJsonPath('data', ['OPEN-LG-REF-730-001', 'OPEN-LG-REF-730-002']);
        $this->getJson("/api/v1/inventory/serials/suggest?product_id={$this->product}&count=2&exclude[]=open-lg-ref-730-001")->assertOk()
            ->assertJsonPath('data', ['OPEN-LG-REF-730-002', 'OPEN-LG-REF-730-003']);
    }

    public function test_owner_opening_stock_is_approved_on_submit_and_posts_against_opening_equity(): void
    {
        $generated = $this->getJson("/api/v1/inventory/serials/suggest?product_id={$this->product}&count=2&exclude[]=REAL-SN-1")->json('data');
        $doc = $this->opening(['REAL-SN-1', ...$generated], $this->owner->id);

        $this->assertSame('approved', $doc->status);
        $this->assertSame('approved', $doc->approval->status);

        $posted = app(PostStockDocument::class)->execute(StockDocumentType::Adjustment, $doc->id, 1, $this->owner->id);

        $this->assertSame('posted', $posted->status);
        $this->assertSame(3, DB::table('serial_numbers')->where('product_id', $this->product)->where('status', 'in_stock')->count());
        $this->assertEquals(3, (float) DB::table('inventory_balances')->where('product_id', $this->product)->sum('qty_on_hand'));
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $posted->posted_journal_entry_id)
            ->selectRaw('a.code, SUM(l.debit) debit, SUM(l.credit) credit')->groupBy('a.code')->get()->keyBy('code');
        $this->assertEquals(300, (float) $lines['1300']->debit);
        $this->assertEquals(300, (float) $lines['3400']->credit);
        // Once issued, a generated number is never suggested again.
        $this->getJson("/api/v1/inventory/serials/suggest?product_id={$this->product}&count=1")->assertJsonPath('data', ['OPEN-LG-REF-730-003']);
    }

    public function test_other_users_still_need_an_approval(): void
    {
        $clerk = User::factory()->create();
        $clerk->roles()->attach(Role::where('name', 'inventory')->firstOrFail());

        $doc = $this->opening(['CLERK-SN-1'], $clerk->id);

        $this->assertSame('pending', $doc->status);
        $this->assertSame('pending', $doc->approval->status);
    }
}
