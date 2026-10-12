<?php

namespace Database\Seeders;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Identity\RoleTemplates;
use App\Domains\Platform\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Brands for the wholesale grocery company «الدويكات للمواد التموينية»: well-known cola,
 * chips, biscuit and mineral water brands in Palestine, Palestinian brands first.
 *
 *   php artisan db:seed --class=GroceryBrandSeeder --force
 *   SEED_COMPANY_ID=2 php artisan db:seed --class=GroceryBrandSeeder --force   (another company)
 *
 * Writes only inside that company, in one transaction, with the same audit entry as adding
 * a brand from the catalog screen. Re-running adds nothing: a brand whose English name
 * already exists is kept as it is. Existing products are not linked to any brand.
 */
class GroceryBrandSeeder extends Seeder
{
    /** [Arabic name, English name] */
    private const BRANDS = [
        // Cola
        ['شات كولا', 'Chat Cola'], // Palestinian, made in Salfit
        ['كوكا كولا', 'Coca-Cola'], // bottled in Palestine by the National Beverage Company
        ['بيبسي', 'Pepsi'],
        // Chips
        ['الطيبات', 'Attayebat'], // Palestinian
        ['ليز', "Lay's"],
        ['دوريتوس', 'Doritos'],
        ['شيتوس', 'Cheetos'],
        ['برينجلز', 'Pringles'],
        // Biscuits
        ['سنقرط', 'Sinokrot'], // Palestinian, Ramallah
        ['أوريو', 'Oreo'],
        ['تك', 'TUC'],
        ['أولكر', 'Ulker'],
        ['لوكر', 'Loacker'],
        // Mineral water
        ['ألاسكا', 'Alaska'], // Palestinian
        ['نستله بيور لايف', 'Nestle Pure Life'],
        ['أكوافينا', 'Aquafina'],
    ];

    public function run(): void
    {
        $company = ($id = (int) env('SEED_COMPANY_ID')) ? Company::find($id) : Company::where('name', GroceryCompanySeeder::COMPANY_NAME)->first();
        if (! $company) {
            throw new RuntimeException('Company "'.GroceryCompanySeeder::COMPANY_NAME.'" not found. Create it from the platform page, or set SEED_COMPANY_ID.');
        }
        app(CompanyContext::class)->run($company->id, fn () => DB::transaction(fn () => $this->seed($company), 3));
    }

    private function seed(Company $company): void
    {
        $actor = User::whereHas('roles', fn ($q) => $q->where('name', RoleTemplates::OWNER))->orderBy('id')->first()
            ?? throw new RuntimeException("Company \"{$company->name}\" has no owner yet. Create its owner from the platform page first.");
        $added = 0;
        foreach (self::BRANDS as [$nameAr, $nameEn]) {
            if (Brand::where('name_en', $nameEn)->exists()) {
                continue;
            }
            $brand = Brand::create(['name_ar' => $nameAr, 'name_en' => $nameEn, 'active' => true]);
            app(RecordAudit::class)->execute('catalog.master_saved', 'brands', $brand->id, null, $brand->toArray(), $actor->id);
            $added++;
        }

        $this->command?->info("{$company->name}: $added brands added.");
    }
}
