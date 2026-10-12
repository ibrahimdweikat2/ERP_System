<?php

namespace Tests\Feature\Platform;

use App\Domains\Catalog\Models\Brand;
use Database\Seeders\GroceryBrandSeeder;
use Database\Seeders\GroceryCompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroceryBrandSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_adds_the_brands_only_to_the_grocery_company_and_can_run_twice(): void
    {
        $this->seed();
        $grocery = $this->makeCompany(GroceryCompanySeeder::COMPANY_NAME);
        $this->companyOwner($grocery);
        $before = Brand::count();

        $this->seed(GroceryBrandSeeder::class);
        $this->seed(GroceryBrandSeeder::class);

        // Company 1 is untouched; the grocery company has each brand exactly once, audited.
        $this->assertSame($before, Brand::count());
        $this->companyContext()->run($grocery->id, function () {
            $this->assertSame(16, Brand::count());
            $this->assertSame('شات كولا', Brand::where('name_en', 'Chat Cola')->value('name_ar'));
            $this->assertSame(16, Brand::where('active', true)->count());
            $this->assertSame(16, DB::table('audit_logs')->where('action', 'catalog.master_saved')->where('entity_type', 'brands')->count());
        });
    }

    public function test_refuses_a_company_without_an_owner(): void
    {
        $this->seed();
        $this->makeCompany(GroceryCompanySeeder::COMPANY_NAME);
        $this->expectExceptionMessage('has no owner yet');
        $this->seed(GroceryBrandSeeder::class);
    }
}
