<?php

namespace Tests\Feature\Tenancy;

use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Models\Role;
use App\Models\User;
use App\Support\Tenancy\MissingCompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The SQL the company-filtering grammar produces. Snapshots also catch Laravel upgrades changing the compiler. */
class TenantGrammarTest extends TestCase
{
    use RefreshDatabase;

    public function test_selects_joins_and_subqueries_are_filtered_once(): void
    {
        $this->assertSame('select * from `products` where `products`.`company_id` = 1', DB::table('products')->toSql());
        $this->assertSame('select * from `products` as `p` where `p`.`company_id` = 1 and (`p`.`active` = ?)', DB::table('products as p')->where('p.active', true)->toSql());
        $this->assertSame('select * from `products` where `products`.`company_id` = 1 and (`sku` = ? or `name_ar` like ?)', DB::table('products')->where('sku', 'A')->orWhere('name_ar', 'like', '%a%')->toSql());
        $this->assertSame(
            'select * from `products` as `p` inner join `units` as `u` on `u`.`company_id` = 1 and (`u`.`id` = `p`.`unit_id`) left join `brands` on `brands`.`company_id` = 1 and (`brands`.`id` = `p`.`brand_id`) where `p`.`company_id` = 1',
            DB::table('products as p')->join('units as u', 'u.id', '=', 'p.unit_id')->leftJoin('brands', 'brands.id', '=', 'p.brand_id')->toSql()
        );
        $this->assertSame(
            'select * from `products` where `products`.`company_id` = 1 and (`id` in (select `product_id` from `product_barcodes` where `product_barcodes`.`company_id` = 1) and exists (select * from `units` where `units`.`company_id` = 1 and (`units`.`id` = `products`.`unit_id`)))',
            DB::table('products')->whereIn('id', DB::table('product_barcodes')->select('product_id'))->whereExists(fn ($q) => $q->from('units')->whereColumn('units.id', 'products.unit_id'))->toSql()
        );
        $this->assertSame(
            '(select `id` from `products` where `products`.`company_id` = 1) union all (select `id` from `customers` where `customers`.`company_id` = 1)',
            DB::table('products')->select('id')->unionAll(DB::table('customers')->select('id'))->toSql()
        );
        // A nested "(a or b)" group is filtered by its parent only.
        $this->assertSame('select * from `products` where `products`.`company_id` = 1 and (`active` = ? and (`sku` = ? or `sku` = ?))', DB::table('products')->where('active', true)->where(fn ($q) => $q->where('sku', 'A')->orWhere('sku', 'B'))->toSql());
    }

    public function test_global_tables_and_system_schemas_are_not_filtered(): void
    {
        $this->assertSame('select * from `permissions`', DB::table('permissions')->toSql());
        $this->assertSame('select * from `companies`', DB::table('companies')->toSql());
        $this->assertSame('select * from `information_schema`.`tables`', DB::table('information_schema.tables')->toSql());
        // A schema-qualified company table is still a company table.
        $this->assertSame('select * from `erp_test`.`products` where `erp_test`.`products`.`company_id` = 1', DB::table('erp_test.products')->toSql());
    }

    public function test_writes_carry_and_respect_the_company(): void
    {
        $id = DB::table('units')->insertGetId(['code' => 'box', 'name_ar' => 'صندوق', 'decimal_places' => 0]);
        $this->assertSame(1, (int) $this->companyContext()->bypass(fn () => DB::table('units')->where('id', $id)->value('company_id')));
        $other = $this->makeCompany();
        $this->companyContext()->run($other->id, fn () => DB::table('units')->insert(['code' => 'box', 'name_ar' => 'صندوق', 'decimal_places' => 0]));
        // Same code in two companies; each sees, updates and deletes only its own row.
        $this->assertSame(1, DB::table('units')->where('code', 'box')->count());
        $this->assertSame(1, DB::table('units')->where('code', 'box')->update(['name_ar' => 'كرتونة']));
        $this->assertSame('صندوق', $this->companyContext()->run($other->id, fn () => DB::table('units')->where('code', 'box')->value('name_ar')));
        $this->assertSame(1, DB::table('units')->where('code', 'box')->delete());
        $this->assertSame(1, $this->companyContext()->run($other->id, fn () => DB::table('units')->where('code', 'box')->count()));

        $this->assertThrows(fn () => DB::table('units')->insert(['code' => 'x', 'name_ar' => 'x', 'company_id' => $other->id]), MissingCompanyContext::class);
        $this->assertThrows(fn () => DB::table('units')->where('code', 'piece')->update(['company_id' => $other->id]), MissingCompanyContext::class);
        $this->assertThrows(fn () => DB::table('units')->truncate(), MissingCompanyContext::class);
        $this->assertThrows(fn () => DB::table('units')->rightJoin('products', 'products.unit_id', '=', 'units.id')->get(), MissingCompanyContext::class);
    }

    public function test_eloquent_relations_pivots_and_upserts_stay_inside_the_company(): void
    {
        $this->seed();
        $other = $this->makeCompany();
        $owner = $this->companyOwner(1);
        $this->assertSame(['owner'], $owner->roles()->pluck('name')->all());
        $this->assertSame(1, User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->count());
        $this->assertSame(5, Role::count());
        $this->assertSame(5, $this->companyContext()->run($other->id, fn () => Role::count()));
        $this->assertSame(1, $this->companyContext()->bypass(fn () => DB::table('user_roles')->where('user_id', $owner->id)->value('company_id')));
        DB::table('account_mappings')->upsert([['key' => 'test_key', 'account_id' => DB::table('accounts')->value('id')]], ['company_id', 'key'], ['account_id']);
        $this->assertTrue(DB::table('account_mappings')->where('key', 'test_key')->exists());
        $this->assertFalse($this->companyContext()->run($other->id, fn () => DB::table('account_mappings')->where('key', 'test_key')->exists()));
        // Pagination counts and pages through the same filter.
        $this->assertSame(Product::count(), Product::paginate(5)->total());
    }

    public function test_no_context_and_platform_mode_refuse_company_tables(): void
    {
        $this->companyContext()->clear();
        $this->assertThrows(fn () => DB::table('products')->get(), MissingCompanyContext::class);
        $this->assertThrows(fn () => DB::table('users')->get(), MissingCompanyContext::class);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->companyContext()->setPlatform();
        $this->assertThrows(fn () => DB::table('products')->count(), MissingCompanyContext::class);
        $this->assertSame('select * from `users` where `users`.`company_id` is null', DB::table('users')->toSql());
    }
}
