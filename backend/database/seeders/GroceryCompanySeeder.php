<?php

namespace Database\Seeders;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Customers\Models\Customer;
use App\Domains\Identity\RoleTemplates;
use App\Domains\Platform\Models\Company;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\Tax\Models\TaxCode;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Starting data for the wholesale grocery company «الدويكات للمواد التموينية»: cola,
 * chips, biscuits and mineral water sold by the carton or shrink pack, their suppliers
 * and the shops that buy from it.
 *
 *   php artisan db:seed --class=GroceryCompanySeeder --force
 *   SEED_COMPANY_ID=2 php artisan db:seed --class=GroceryCompanySeeder --force   (another company)
 *
 * Writes only inside that company, through the same actions the screens use, in one
 * transaction. Re-running adds nothing: existing codes and SKUs are kept as they are.
 * Supplier and customer names are illustrative; prices are approximate wholesale shekel
 * prices per carton or pack, to be reviewed before trading. No stock, balances or
 * documents are created.
 */
class GroceryCompanySeeder extends Seeder
{
    public const COMPANY_NAME = 'الدويكات للمواد التموينية';

    /** code => [Arabic name, decimal places]; wholesale sells whole cartons and shrink packs only. */
    private const UNITS = ['carton' => ['كرتونة', 0], 'pack' => ['شدّة', 0]];

    /** slug => [Arabic, English] */
    private const CATEGORIES = [
        'cola' => ['الكولا', 'Cola'],
        'chips' => ['الشيبس', 'Chips'],
        'biscuits' => ['البسكويت', 'Biscuits'],
        'water' => ['المياه المعدنية', 'Mineral water'],
    ];

    public function run(): void
    {
        $context = app(CompanyContext::class);
        $company = ($id = (int) env('SEED_COMPANY_ID')) ? Company::find($id) : Company::where('name', self::COMPANY_NAME)->first();
        if (! $company) {
            throw new RuntimeException('Company "'.self::COMPANY_NAME.'" not found. Create it from the platform page, or set SEED_COMPANY_ID.');
        }
        $context->run($company->id, fn () => DB::transaction(fn () => $this->seed($company), 3));
    }

    private function seed(Company $company): void
    {
        $actor = User::whereHas('roles', fn ($q) => $q->where('name', RoleTemplates::OWNER))->orderBy('id')->first()
            ?? throw new RuntimeException("Company \"{$company->name}\" has no owner yet. Create its owner from the platform page first.");
        $added = ['units' => 0, 'categories' => 0, 'suppliers' => 0, 'products' => 0, 'customers' => 0];

        foreach (self::UNITS as $code => [$name, $decimals]) {
            $added['units'] += (int) Unit::firstOrCreate(['code' => $code], ['name_ar' => $name, 'decimal_places' => $decimals])->wasRecentlyCreated;
        }
        foreach (self::CATEGORIES as $slug => [$nameAr, $nameEn]) {
            $added['categories'] += (int) Category::firstOrCreate(['slug' => $slug], ['name_ar' => $nameAr, 'name_en' => $nameEn, 'active' => true])->wasRecentlyCreated;
        }

        foreach ($this->suppliers() as [$code, $name, $address, $contact, $title, $supplies, $terms, $limit]) {
            if (Supplier::where('code', $code)->exists()) {
                continue;
            }
            app(SaveSupplier::class)->execute([
                'code' => $code, 'legal_name' => $name, 'trade_name' => $name, 'tax_number' => null, 'address' => $address,
                'contacts' => [['name' => $contact, 'phone' => null, 'email' => null, 'title' => $title]],
                'currency' => 'ILS', 'payment_terms_days' => $terms, 'credit_limit' => $limit, 'active' => true, 'notes' => 'يورّد: '.$supplies,
            ], $actor->id);
            $added['suppliers']++;
        }

        $tax = $this->vat();
        $units = Unit::pluck('id', 'code');
        $categories = Category::pluck('id', 'slug');
        foreach ($this->products() as [$sku, $nameAr, $nameEn, $category, $unit, $contents, $itemSize, $cost, $price, $minimum]) {
            if (Product::where('sku', $sku)->exists()) {
                continue;
            }
            app(SaveProduct::class)->execute([
                'sku' => $sku, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'manufacturer_model' => null,
                'brand_id' => null, 'category_id' => $categories[$category], 'unit_id' => $units[$unit], 'warranty_policy_id' => null, 'tax_code_id' => $tax,
                'serial_tracked' => false, 'active' => true, 'standard_cost' => $cost, 'cash_price' => $price, 'installment_price' => $price,
                'minimum_price' => $minimum, 'reorder_level' => '10', 'energy_rating' => null, 'country_of_origin' => null,
                'specifications' => [['name' => 'محتوى الكرتونة / الشدّة', 'value' => $contents], ['name' => 'حجم القطعة', 'value' => $itemSize]], 'barcodes' => [],
            ], $actor->id);
            $added['products']++;
        }

        foreach ($this->customers() as [$code, $name, $address, $kind, $limit, $walkIn]) {
            if (Customer::where('code', $code)->exists() || ($walkIn && Customer::where('is_walk_in', true)->exists())) {
                continue;
            }
            // Same fields and audit entry as saving a customer from the customers screen.
            $customer = Customer::create(['code' => $code, 'name' => $name, 'phone' => null, 'address' => $address, 'workplace' => $kind, 'currency' => 'ILS',
                'credit_limit' => $limit, 'max_active_contracts' => 0, 'max_overdue_days' => $walkIn ? 0 : 30, 'risk_flag' => 'normal', 'is_walk_in' => $walkIn,
                'active' => true, 'version' => 1, 'created_by' => $actor->id]);
            app(RecordAudit::class)->execute('customers.created', 'customer', $customer->id, null, $customer->toArray(), $actor->id);
            $added['customers']++;
        }

        $this->command?->info("{$company->name}: ".collect($added)->map(fn ($n, $what) => "$n $what")->implode(', ').' added.');
    }

    /** The company's standard VAT code; created at 16% when the company has none yet. */
    private function vat(): int
    {
        $existing = TaxCode::where('category', 'standard')->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', today()))->orderByDesc('effective_from')->value('id');

        return $existing ?? TaxCode::create(['code' => 'VAT16', 'name_ar' => 'ضريبة القيمة المضافة 16%', 'category' => 'standard', 'rate' => '16', 'effective_from' => '2026-01-01',
            'input_account_id' => DB::table('accounts')->where('code', '1400')->value('id'), 'output_account_id' => DB::table('accounts')->where('code', '2200')->value('id')])->id;
    }

    /** code, name, address, contact person, contact title, what they supply, payment days, credit limit */
    private function suppliers(): array
    {
        return [
            ['SUP-GR-01', 'شركة الشرق لتعبئة المشروبات الغازية', 'رام الله - المنطقة الصناعية', 'وسيم الشيخ', 'مدير المبيعات', 'الكولا والكولا زيرو بالعلب والعبوات البلاستيكية', 30, '120000'],
            ['SUP-GR-02', 'مصنع رقائق البطاطا الذهبية', 'نابلس - المنطقة الصناعية الشرقية', 'سامر عبد الهادي', 'مندوب المبيعات', 'شيبس البطاطا بالنكهات والأحجام', 30, '60000'],
            ['SUP-GR-03', 'مصنع سنابل للمقرمشات', 'جنين - شارع حيفا', 'محمد أبو الرب', 'صاحب المصنع', 'شيبس الذرة', 30, '30000'],
            ['SUP-GR-04', 'شركة الوادي لتوزيع البسكويت والويفر', 'الخليل - المنطقة الصناعية', 'رائد التميمي', 'مندوب التوزيع', 'بسكويت الشاي والمحشو والمغطى بالشوكولاتة والويفر', 30, '50000'],
            ['SUP-GR-05', 'مخابز النخيل للبسكويت', 'أريحا - طريق الأغوار', 'إياد سلامة', 'المدير', 'بسكويت التمر والدايجستف والبسكويت المشكّل', 45, '40000'],
            ['SUP-GR-06', 'شركة ينابيع الجبل للمياه المعدنية', 'طوباس - المدخل الشمالي', 'أحمد دراغمة', 'مدير المبيعات', 'المياه المعدنية بجميع الأحجام والمياه الفوّارة', 30, '50000'],
        ];
    }

    /** sku, Arabic name, English name, category, unit, carton/pack contents, item size, cost, wholesale price, minimum price (ILS per carton/pack, approximate) */
    private function products(): array
    {
        return [
            ['GR-COL-001', 'كولا علب 330 مل - كرتونة 24 علبة', 'Cola cans 330ml, carton of 24', 'cola', 'carton', '24 علبة', '330 مل', '42.00', '48.00', '45.00'],
            ['GR-COL-002', 'كولا زيرو بدون سكر علب 330 مل - كرتونة 24 علبة', 'Zero-sugar cola cans 330ml, carton of 24', 'cola', 'carton', '24 علبة', '330 مل', '42.00', '48.00', '45.00'],
            ['GR-COL-003', 'كولا علب صغيرة 250 مل - كرتونة 24 علبة', 'Cola slim cans 250ml, carton of 24', 'cola', 'carton', '24 علبة', '250 مل', '34.00', '39.00', '37.00'],
            ['GR-COL-004', 'كولا عبوات 500 مل - شدّة 12 عبوة', 'Cola bottles 500ml, pack of 12', 'cola', 'pack', '12 عبوة', '500 مل', '30.00', '35.00', '33.00'],
            ['GR-COL-005', 'كولا عبوات 1.5 لتر - شدّة 6 عبوات', 'Cola bottles 1.5L, pack of 6', 'cola', 'pack', '6 عبوات', '1.5 لتر', '30.00', '34.00', '32.00'],
            ['GR-COL-006', 'كولا زيرو بدون سكر عبوات 1.5 لتر - شدّة 6 عبوات', 'Zero-sugar cola bottles 1.5L, pack of 6', 'cola', 'pack', '6 عبوات', '1.5 لتر', '30.00', '34.00', '32.00'],
            ['GR-COL-007', 'كولا عبوات 2 لتر - شدّة 6 عبوات', 'Cola bottles 2L, pack of 6', 'cola', 'pack', '6 عبوات', '2 لتر', '36.00', '41.00', '39.00'],
            ['GR-CHP-001', 'شيبس بطاطا مملح 25 غم - كرتونة 48 كيس', 'Salted potato chips 25g, carton of 48', 'chips', 'carton', '48 كيس', '25 غم', '32.00', '37.00', '35.00'],
            ['GR-CHP-002', 'شيبس بطاطا بالجبنة 25 غم - كرتونة 48 كيس', 'Cheese potato chips 25g, carton of 48', 'chips', 'carton', '48 كيس', '25 غم', '32.00', '37.00', '35.00'],
            ['GR-CHP-003', 'شيبس بطاطا بالكاتشب 25 غم - كرتونة 48 كيس', 'Ketchup potato chips 25g, carton of 48', 'chips', 'carton', '48 كيس', '25 غم', '32.00', '37.00', '35.00'],
            ['GR-CHP-004', 'شيبس بطاطا بالشطة الحارة 25 غم - كرتونة 48 كيس', 'Hot chili potato chips 25g, carton of 48', 'chips', 'carton', '48 كيس', '25 غم', '32.00', '37.00', '35.00'],
            ['GR-CHP-005', 'شيبس بطاطا بالملح والخل 25 غم - كرتونة 48 كيس', 'Salt and vinegar potato chips 25g, carton of 48', 'chips', 'carton', '48 كيس', '25 غم', '32.00', '37.00', '35.00'],
            ['GR-CHP-006', 'شيبس بطاطا مملح 75 غم - كرتونة 24 كيس', 'Salted potato chips 75g, carton of 24', 'chips', 'carton', '24 كيس', '75 غم', '56.00', '64.00', '60.00'],
            ['GR-CHP-007', 'شيبس بطاطا بالباربكيو 75 غم - كرتونة 24 كيس', 'Barbecue potato chips 75g, carton of 24', 'chips', 'carton', '24 كيس', '75 غم', '56.00', '64.00', '60.00'],
            ['GR-CHP-008', 'شيبس ذرة بالجبنة 75 غم - كرتونة 24 كيس', 'Cheese corn chips 75g, carton of 24', 'chips', 'carton', '24 كيس', '75 غم', '54.00', '62.00', '58.00'],
            ['GR-CHP-009', 'شيبس بطاطا مملح حجم عائلي 160 غم - كرتونة 12 كيس', 'Salted potato chips family size 160g, carton of 12', 'chips', 'carton', '12 كيس', '160 غم', '66.00', '75.00', '71.00'],
            ['GR-BIS-001', 'بسكويت شاي سادة 100 غم - كرتونة 24 عبوة', 'Plain tea biscuits 100g, carton of 24', 'biscuits', 'carton', '24 عبوة', '100 غم', '30.00', '35.00', '33.00'],
            ['GR-BIS-002', 'بسكويت محشو بكريمة الشوكولاتة 45 غم - كرتونة 24 عبوة', 'Chocolate cream biscuits 45g, carton of 24', 'biscuits', 'carton', '24 عبوة', '45 غم', '26.00', '30.00', '28.00'],
            ['GR-BIS-003', 'بسكويت محشو بكريمة الفانيلا 45 غم - كرتونة 24 عبوة', 'Vanilla cream biscuits 45g, carton of 24', 'biscuits', 'carton', '24 عبوة', '45 غم', '26.00', '30.00', '28.00'],
            ['GR-BIS-004', 'ويفر بالشوكولاتة 40 غم - كرتونة 24 قطعة', 'Chocolate wafers 40g, carton of 24', 'biscuits', 'carton', '24 قطعة', '40 غم', '24.00', '28.00', '26.00'],
            ['GR-BIS-005', 'ويفر بالبندق 40 غم - كرتونة 24 قطعة', 'Hazelnut wafers 40g, carton of 24', 'biscuits', 'carton', '24 قطعة', '40 غم', '24.00', '28.00', '26.00'],
            ['GR-BIS-006', 'بسكويت مغطى بالشوكولاتة 30 غم - كرتونة 24 قطعة', 'Chocolate-coated biscuits 30g, carton of 24', 'biscuits', 'carton', '24 قطعة', '30 غم', '34.00', '40.00', '37.00'],
            ['GR-BIS-007', 'بسكويت دايجستف بالقمح الكامل 250 غم - كرتونة 12 عبوة', 'Wholewheat digestive biscuits 250g, carton of 12', 'biscuits', 'carton', '12 عبوة', '250 غم', '60.00', '69.00', '65.00'],
            ['GR-BIS-008', 'بسكويت محشو بالتمر 200 غم - كرتونة 12 عبوة', 'Date-filled biscuits 200g, carton of 12', 'biscuits', 'carton', '12 عبوة', '200 غم', '54.00', '62.00', '58.00'],
            ['GR-BIS-009', 'بسكويت مشكّل علبة 400 غم - كرتونة 12 علبة', 'Assorted biscuits tin 400g, carton of 12', 'biscuits', 'carton', '12 علبة', '400 غم', '90.00', '102.00', '96.00'],
            ['GR-WAT-001', 'مياه معدنية 330 مل - شدّة 24 عبوة', 'Mineral water 330ml, pack of 24', 'water', 'pack', '24 عبوة', '330 مل', '15.00', '18.00', '17.00'],
            ['GR-WAT-002', 'مياه معدنية 500 مل - شدّة 12 عبوة', 'Mineral water 500ml, pack of 12', 'water', 'pack', '12 عبوة', '500 مل', '10.00', '12.00', '11.00'],
            ['GR-WAT-003', 'مياه معدنية 1.5 لتر - شدّة 6 عبوات', 'Mineral water 1.5L, pack of 6', 'water', 'pack', '6 عبوات', '1.5 لتر', '10.00', '12.50', '11.50'],
            ['GR-WAT-004', 'مياه معدنية فوّارة 330 مل - شدّة 24 عبوة', 'Sparkling mineral water 330ml, pack of 24', 'water', 'pack', '24 عبوة', '330 مل', '40.00', '46.00', '43.00'],
        ];
    }

    /** code, name, address, type of business, credit limit, walk-in */
    private function customers(): array
    {
        return [
            ['GC-001', 'سوبرماركت الأمانة', 'نابلس - شارع فيصل', 'سوبرماركت', '15000', false],
            ['GC-002', 'بقالة النور', 'نابلس - رفيديا', 'بقالة', '5000', false],
            ['GC-003', 'ميني ماركت الريف', 'بيتا - الشارع الرئيسي', 'ميني ماركت', '6000', false],
            ['GC-004', 'سوبرماركت البيادر', 'البيرة - شارع القدس', 'سوبرماركت', '20000', false],
            ['GC-005', 'بقالة أبو العبد', 'روجيب - المدخل الرئيسي', 'بقالة', '3000', false],
            ['GC-006', 'كافتيريا الطلاب', 'نابلس - شارع الجامعة', 'كافتيريا', '4000', false],
            ['GC-007', 'مقصف المدرسة', 'حوارة - قرب المدارس', 'مقصف مدرسي', '2000', false],
            ['GC-008', 'سوبرماركت الأقصى', 'جنين - شارع أبو بكر', 'سوبرماركت', '12000', false],
            ['GC-009', 'بقالة السلام', 'طولكرم - شارع نابلس', 'بقالة', '4000', false],
            ['GC-010', 'محل الخير للمواد التموينية', 'حوارة - الشارع الرئيسي', 'محل مواد تموينية', '10000', false],
            ['GC-011', 'كشك الدوار', 'نابلس - دوار الشهداء', 'كشك', '2000', false],
            ['GC-012', 'استراحة الطريق', 'زعترة - الشارع الرئيسي', 'استراحة', '5000', false],
            ['GC-013', 'قاعة الياسمين للمناسبات', 'نابلس - شارع عمان', 'قاعة مناسبات', '6000', false],
            ['GC-014', 'ماركت محطة الوقود', 'عصيرة الشمالية - المدخل', 'ماركت محطة وقود', '8000', false],
            ['GC-CASH', 'عميل نقدي عابر', null, 'بيع نقدي', '0', true],
        ];
    }
}
