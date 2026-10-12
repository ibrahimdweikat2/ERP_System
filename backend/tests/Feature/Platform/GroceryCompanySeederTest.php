<?php

namespace Tests\Feature\Platform;

use App\Domains\Catalog\Models\Product;
use App\Domains\Customers\Models\Customer;
use App\Domains\Purchasing\Models\Supplier;
use Database\Seeders\GroceryCompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroceryCompanySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_only_the_grocery_company_and_can_run_twice(): void
    {
        $this->seed();
        $grocery = $this->makeCompany(GroceryCompanySeeder::COMPANY_NAME);
        $this->companyOwner($grocery);
        $counts = fn () => [Product::count(), Supplier::count(), Customer::count()];
        $before = $counts();

        $this->seed(GroceryCompanySeeder::class);
        $this->seed(GroceryCompanySeeder::class);

        // Company 1 is untouched; the grocery company has the full set exactly once.
        $this->assertSame($before, $counts());
        $this->companyContext()->run($grocery->id, function () {
            $this->assertSame(29, Product::count());
            $this->assertSame(6, Supplier::count());
            $this->assertSame(15, Customer::count());
            $cola = Product::with('unit', 'category')->where('sku', 'GR-COL-001')->firstOrFail();
            $this->assertSame('كولا علب 330 مل - كرتونة 24 علبة', $cola->name_ar);
            $this->assertSame('carton', $cola->unit->code);
            $this->assertSame('cola', $cola->category->slug);
            $this->assertNotNull($cola->tax_code_id);
            $this->assertFalse((bool) $cola->serial_tracked);
            // Wholesale only: cola, chips, biscuits and mineral water.
            $this->assertSame(['biscuits', 'chips', 'cola', 'water'], Product::with('category')->get()->pluck('category.slug')->unique()->sort()->values()->all());
            $this->assertSame(1, Customer::where('is_walk_in', true)->count());
        });
    }

    public function test_refuses_a_company_without_an_owner(): void
    {
        $this->seed();
        $this->makeCompany(GroceryCompanySeeder::COMPANY_NAME);
        $this->expectExceptionMessage('has no owner yet');
        $this->seed(GroceryCompanySeeder::class);
    }
}
