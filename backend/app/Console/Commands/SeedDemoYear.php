<?php

namespace App\Console\Commands;

use App\Domains\Accounting\Actions\SaveAccountingMaster;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Product;
use App\Domains\Checks\Actions\ManageCheck;
use App\Domains\Customers\Models\Customer;
use App\Domains\Expenses\Actions\ManageExpense;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Payments\Actions\ReceiveCustomerPayment;
use App\Domains\Purchasing\Actions\PaySupplier;
use App\Domains\Purchasing\Actions\PostGoodsReceipt;
use App\Domains\Purchasing\Actions\PostSupplierInvoice;
use App\Domains\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domains\Purchasing\Actions\SaveGoodsReceipt;
use App\Domains\Purchasing\Actions\SavePurchaseOrder;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Domains\Purchasing\Actions\SaveSupplierInvoice;
use App\Domains\Sales\Actions\PostSalesInvoice;
use App\Domains\Sales\Actions\SaveSalesInvoice;
use App\Domains\Tax\Models\TaxCode;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generates a demonstration year through the real business actions, so every
 * journal, stock movement, installment and check behaves exactly as in live use.
 * Demonstration data only: prices, suppliers and customers are invented.
 */
class SeedDemoYear extends Command
{
    protected $signature = 'erp:demo-year {--force : Run although the store already holds documents}';

    protected $description = 'Seed a full demonstration year of catalog, purchasing, sales, installments and checks';

    public const CATEGORIES = [
        'ref' => ['ثلاجات وفريزرات', 'Refrigerators'], 'wm' => ['غسالات ملابس', 'Washing machines'], 'dw' => ['غسالات صحون', 'Dishwashers'],
        'ovn' => ['أفران وطباخات', 'Ovens and cookers'], 'ac' => ['مكيفات', 'Air conditioners'], 'wh' => ['سخانات مياه', 'Water heaters'],
        'vac' => ['مكانس كهربائية', 'Vacuum cleaners'], 'sda' => ['أجهزة مطبخ صغيرة', 'Small appliances'],
    ];

    private User $actor;

    private int $showroom;

    private int $warehouse;

    private int $vat;

    private int $cashbox;

    private ?int $bank = null;

    private ?int $bankUsd = null;

    /** @var array<string,Product> */
    private array $products = [];

    /** @var array<int,array{id:int,name:string}> */
    private array $suppliers = [];

    /** @var array<int,array{id:int,name:string}> */
    private array $customers = [];

    private array $counts = ['products' => 0, 'suppliers' => 0, 'customers' => 0, 'purchase_orders' => 0, 'receipts' => 0, 'supplier_invoices' => 0, 'supplier_payments' => 0, 'sales' => 0, 'installment_sales' => 0, 'payments' => 0, 'checks' => 0, 'expenses' => 0];

    public function handle(): int
    {
        mt_srand(20260101);
        $existing = DB::table('sales_invoices')->count() + DB::table('goods_receipts')->count();
        if ($existing > 0 && ! $this->option('force')) {
            $this->error("The store already holds $existing operational documents. Re-run with --force only if demonstration data may be added to it.");

            return self::FAILURE;
        }
        $this->actor = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->orderBy('id')->firstOrFail();
        $this->showroom = StockLocation::where('code', 'SHOWROOM')->orWhere('sellable', true)->value('id');
        $this->warehouse = StockLocation::where('code', 'WAREHOUSE')->value('id') ?? $this->showroom;
        $this->cashbox = (int) DB::table('cashboxes')->where('active', true)->orderBy('id')->value('id');

        $this->info('Master data…');
        $this->masterData();
        $this->info('Suppliers and catalog…');
        $this->suppliersAndProducts();
        $this->info('Customers…');
        $this->customers();
        $this->info('Month by month: purchases, sales, collections and expenses…');
        // One chronological pass: stock must be received before it can be sold.
        foreach (range(1, 9) as $month) {
            $this->purchasing($month);
            $this->sales($month);
            $this->expenses($month);
            $this->output->write('.');
        }
        $this->newLine();

        $this->newLine();
        foreach ($this->counts as $key => $value) {
            $this->line(sprintf('  %-20s %d', $key, $value));
        }

        return self::SUCCESS;
    }

    private function master(string $kind, array $data): int
    {
        return app(SaveAccountingMaster::class)->execute($kind, $data, $this->actor->id)->id;
    }

    private function masterData(): void
    {
        $vat = TaxCode::where('code', 'VAT16')->first();
        if (! $vat) {
            // Demonstration rate. Confirm the statutory rate with the accountant before live use.
            $vat = TaxCode::create(['code' => 'VAT16', 'name_ar' => 'ضريبة القيمة المضافة 16% (بيانات تجريبية)', 'category' => 'standard', 'rate' => '16', 'effective_from' => '2026-01-01',
                'input_account_id' => DB::table('accounts')->where('code', '1400')->value('id'), 'output_account_id' => DB::table('accounts')->where('code', '2200')->value('id')]);
        }
        $this->vat = $vat->id;
        if (! DB::table('bank_accounts')->exists()) {
            $account = DB::table('accounts')->where('code', '1100')->value('id') ?? DB::table('accounts')->where('account_type', 'asset')->value('id');
            $this->bank = \App\Domains\CashBank\Models\BankAccount::create(['name' => 'حساب بنك فلسطين - الجاري', 'bank_name' => 'بنك فلسطين', 'account_number' => '0091-334455', 'iban' => 'PS92PALS000000000091334455', 'account_id' => $account, 'currency_code' => 'ILS', 'active' => true])->id;
        } else {
            $this->bank = (int) DB::table('bank_accounts')->where('currency_code', 'ILS')->orderBy('id')->value('id');
        }
        // Importers invoice in USD, and a treasury account must match the document currency.
        $this->bankUsd = (int) (DB::table('bank_accounts')->where('currency_code', 'USD')->value('id')
            ?? \App\Domains\CashBank\Models\BankAccount::create(['name' => 'حساب البنك بالدولار', 'bank_name' => 'البنك العربي', 'account_number' => '0077-998877', 'iban' => 'PS31ARAB000000000077998877', 'account_id' => $this->usdBankAccount(), 'currency_code' => 'USD', 'active' => true])->id);
        // The demonstration recognises the full installment price at sale. The owner
        // and the accountant choose this policy for real operation.
        $installments = DB::table('business_policies')->where('key', 'installments')->first();
        if ($installments) {
            $settings = json_decode($installments->settings, true, 512, JSON_THROW_ON_ERROR);
            if (($settings['markup_recognition'] ?? null) !== 'full_price_at_sale') {
                $settings['markup_recognition'] = 'full_price_at_sale';
                DB::table('business_policies')->where('key', 'installments')->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'version' => $installments->version + 1, 'updated_at' => now()]);
            }
        }
        // Illustrative monthly rates for the imported-goods currencies.
        foreach (range(1, 12) as $month) {
            foreach (['USD' => 3.70, 'JOD' => 5.22] as $currency => $base) {
                DB::table('exchange_rates')->insertOrIgnore(['currency_code' => $currency, 'rate_date' => sprintf('2026-%02d-01', $month), 'rate_to_base' => number_format($base + ($month % 4) * 0.02, 4, '.', ''), 'source' => 'بيانات تجريبية', 'locked' => false, 'created_by' => $this->actor->id, 'created_at' => now()]);
            }
        }
        foreach (['LG', 'Samsung', 'Bosch', 'Beko', 'Ariston', 'Toshiba', 'Hisense', 'Midea', 'Panasonic', 'National'] as $brand) {
            DB::table('brands')->insertOrIgnore(['name_ar' => $brand, 'name_en' => $brand, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        // slug is unique and also feeds the category segment of generated SKUs.
        foreach (self::CATEGORIES as $slug => [$nameAr, $nameEn]) {
            DB::table('categories')->updateOrInsert(['slug' => $slug], ['name_ar' => $nameAr, 'name_en' => $nameEn, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach ([['ضمان الوكيل سنة واحدة', 1, 'year'], ['ضمان الوكيل سنتان', 2, 'year'], ['ضمان الكمبروسر 5 سنوات', 5, 'year']] as [$name, $value, $unit]) {
            DB::table('warranty_policies')->insertOrIgnore(['name_ar' => $name, 'duration_value' => $value, 'duration_unit' => $unit, 'provider_type' => 'agent', 'terms' => 'الضمان لدى الوكيل المعتمد ولا يشمل سوء الاستخدام.', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Each treasury account needs its own general-ledger account. */
    private function usdBankAccount(): int
    {
        $existing = DB::table('accounts')->where('code', '1102')->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $parent = DB::table('accounts')->where('code', '1100')->value('id');

        return (int) DB::table('accounts')->insertGetId(['code' => '1102', 'name_ar' => 'بنك - حساب الدولار', 'name_en' => 'Bank - USD account', 'parent_id' => $parent,
            'account_type' => 'asset', 'normal_balance' => 'debit', 'is_control_account' => false, 'allow_manual_posting' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function suppliersAndProducts(): void
    {
        $catalogue = $this->catalogue();
        foreach ($this->supplierDefinitions() as $index => $definition) {
            // Re-runnable: a supplier or product created by an earlier run is reused.
            $known = \App\Domains\Purchasing\Models\Supplier::where('code', $definition['code'])->first();
            if ($known) {
                $this->suppliers[$index] = ['id' => $known->id, 'name' => $definition['name'], 'currency' => $definition['currency'], 'brands' => $definition['brands']];

                continue;
            }
            $supplier = app(SaveSupplier::class)->execute([
                'code' => $definition['code'], 'legal_name' => $definition['name'], 'trade_name' => $definition['name'], 'tax_number' => $definition['tax'],
                'address' => $definition['address'], 'currency' => $definition['currency'], 'contacts' => $definition['contacts'],
                'payment_terms_days' => $definition['terms'], 'credit_limit' => $definition['limit'], 'active' => true,
                'notes' => 'يورّد: '.$definition['supplies'],
            ], $this->actor->id);
            $this->suppliers[$index] = ['id' => $supplier->id, 'name' => $definition['name'], 'currency' => $definition['currency'], 'brands' => $definition['brands']];
            $this->counts['suppliers']++;
        }
        $brands = DB::table('brands')->pluck('id', 'name_ar');
        $categories = DB::table('categories')->pluck('id', 'name_ar');
        $warranties = DB::table('warranty_policies')->pluck('id', 'name_ar');
        $unit = (int) DB::table('units')->where('code', 'piece')->value('id');
        foreach ($catalogue as $row) {
            $known = Product::where('sku', $row['sku'])->first();
            if ($known) {
                $this->products[$row['sku']] = $known;

                continue;
            }
            $product = app(SaveProduct::class)->execute([
                'sku' => $row['sku'], 'name_ar' => $row['name'], 'name_en' => $row['en'], 'manufacturer_model' => $row['model'],
                'brand_id' => $brands[$row['brand']] ?? null, 'category_id' => $categories[$row['category']] ?? null, 'unit_id' => $unit,
                'warranty_policy_id' => $warranties[$row['warranty']] ?? null, 'tax_code_id' => $this->vat,
                'serial_tracked' => true, 'active' => true, 'standard_cost' => $row['cost'],
                'cash_price' => $row['cash'], 'installment_price' => $row['installment'], 'minimum_price' => $row['minimum'], 'reorder_level' => '2',
                'energy_rating' => $row['energy'], 'country_of_origin' => $row['origin'],
                'specifications' => $row['specs'], 'barcodes' => [$row['barcode']],
            ], $this->actor->id);
            $this->products[$row['sku']] = $product;
            $this->counts['products']++;
        }
    }

    private function supplierDefinitions(): array
    {
        return [
            ['code' => 'SUP-LG-01', 'name' => 'شركة الوكيل الوطني للأجهزة - LG', 'tax' => '901234567', 'address' => 'رام الله - المنطقة الصناعية', 'currency' => 'ILS', 'terms' => 30, 'limit' => '150000', 'brands' => ['LG'],
                'supplies' => 'ثلاثات وغسالات ومكيفات LG', 'contacts' => [['name' => 'سامي عبد الله', 'role' => 'مدير مبيعات', 'phone' => '0599123456', 'email' => 'sami@lg-agent.ps']]],
            ['code' => 'SUP-SAM-02', 'name' => 'مؤسسة النجمة لتجارة الأجهزة - Samsung', 'tax' => '902345678', 'address' => 'نابلس - شارع فيصل', 'currency' => 'ILS', 'terms' => 45, 'limit' => '120000', 'brands' => ['Samsung'],
                'supplies' => 'ثلاجات وغسالات Samsung', 'contacts' => [['name' => 'رامي حجاوي', 'role' => 'مندوب', 'phone' => '0598234567', 'email' => 'rami@najma.ps']]],
            ['code' => 'SUP-EUR-03', 'name' => 'الشركة الأوروبية للاستيراد - Bosch/Ariston', 'tax' => '903456789', 'address' => 'الخليل - المنطقة الصناعية', 'currency' => 'USD', 'terms' => 60, 'limit' => '80000', 'brands' => ['Bosch', 'Ariston'],
                'supplies' => 'غسالات صحون وأفران وسخانات', 'contacts' => [['name' => 'جورج قمصية', 'role' => 'مدير استيراد', 'phone' => '0597345678', 'email' => 'george@euro-import.ps']]],
            ['code' => 'SUP-BEK-04', 'name' => 'شركة الشرق للأجهزة المنزلية - Beko', 'tax' => '904567890', 'address' => 'جنين - شارع حيفا', 'currency' => 'ILS', 'terms' => 30, 'limit' => '60000', 'brands' => ['Beko', 'Toshiba'],
                'supplies' => 'غسالات وثلاجات اقتصادية', 'contacts' => [['name' => 'محمود زيد', 'role' => 'مندوب مبيعات', 'phone' => '0596456789', 'email' => 'mahmoud@sharq.ps']]],
            ['code' => 'SUP-ASIA-05', 'name' => 'آسيا تريدنغ - Midea/Hisense', 'tax' => '905678901', 'address' => 'بيت لحم - شارع المهد', 'currency' => 'USD', 'terms' => 45, 'limit' => '70000', 'brands' => ['Midea', 'Hisense'],
                'supplies' => 'مكيفات وأجهزة صغيرة', 'contacts' => [['name' => 'عماد سلامة', 'role' => 'مدير حساب', 'phone' => '0595567890', 'email' => 'imad@asia-trading.ps']]],
            ['code' => 'SUP-LOC-06', 'name' => 'مستودعات المحلية للأجهزة الصغيرة', 'tax' => '906789012', 'address' => 'طولكرم - السوق التجاري', 'currency' => 'ILS', 'terms' => 15, 'limit' => '30000', 'brands' => ['Panasonic', 'National'],
                'supplies' => 'مكانس وأجهزة مطبخ صغيرة', 'contacts' => [['name' => 'أحمد دراغمة', 'role' => 'صاحب المستودع', 'phone' => '0594678901', 'email' => 'ahmad@mahalliya.ps']]],
        ];
    }

    private function catalogue(): array
    {
        $rows = [
            ['LG-REF-GN730', 'ثلاجة LG نوفروست 730 لتر', 'LG No Frost Refrigerator 730L', 'GN-C732HQCL', 'LG', 'ثلاجات وفريزرات', 'ضمان الكمبروسر 5 سنوات', '3200', '4300', '4900', '3900', 'A+', 'كوريا', ['السعة' => '730 لتر', 'النوع' => 'بابين نوفروست', 'اللون' => 'ستيل']],
            ['LG-REF-GN340', 'ثلاجة LG 340 لتر', 'LG Refrigerator 340L', 'GN-B342SQBB', 'LG', 'ثلاجات وفريزرات', 'ضمان الوكيل سنتان', '1900', '2600', '2950', '2350', 'A', 'كوريا', ['السعة' => '340 لتر', 'اللون' => 'أسود']],
            ['LG-WM-F4V5', 'غسالة LG أوتوماتيك 9 كغم', 'LG Front Load Washer 9kg', 'F4V5VYP2T', 'LG', 'غسالات ملابس', 'ضمان الوكيل سنتان', '2100', '2850', '3250', '2600', 'A+++', 'كوريا', ['السعة' => '9 كغم', 'الدورة' => '1400 لفة']],
            ['LG-AC-S18', 'مكيف LG انفرتر 1.5 طن', 'LG Inverter Split AC 18000BTU', 'S4-W18KL3AA', 'LG', 'مكيفات', 'ضمان الكمبروسر 5 سنوات', '2400', '3200', '3650', '2900', 'A++', 'كوريا', ['القدرة' => '18000 BTU', 'النوع' => 'انفرتر']],
            ['SAM-REF-RT50', 'ثلاجة Samsung تويين كولينغ 500 لتر', 'Samsung Twin Cooling 500L', 'RT50K6340S8', 'Samsung', 'ثلاجات وفريزرات', 'ضمان الكمبروسر 5 سنوات', '2800', '3800', '4300', '3400', 'A+', 'كوريا', ['السعة' => '500 لتر', 'التقنية' => 'تبريد مزدوج']],
            ['SAM-WM-WW90', 'غسالة Samsung إيكو بابل 9 كغم', 'Samsung EcoBubble Washer 9kg', 'WW90T504DAE', 'Samsung', 'غسالات ملابس', 'ضمان الوكيل سنتان', '2200', '2950', '3400', '2700', 'A+++', 'بولندا', ['السعة' => '9 كغم', 'التقنية' => 'إيكو بابل']],
            ['SAM-MW-MS23', 'مايكروويف Samsung 23 لتر', 'Samsung Microwave 23L', 'MS23K3513AK', 'Samsung', 'أجهزة مطبخ صغيرة', 'ضمان الوكيل سنة واحدة', '380', '540', '620', '480', 'A', 'ماليزيا', ['السعة' => '23 لتر', 'القدرة' => '800 واط']],
            ['BSH-DW-SMS4', 'غسالة صحون Bosch 12 طقم', 'Bosch Dishwasher 12 Place', 'SMS4HVI33E', 'Bosch', 'غسالات صحون', 'ضمان الوكيل سنتان', '2500', '3400', '3900', '3100', 'A++', 'ألمانيا', ['السعة' => '12 طقم', 'البرامج' => '6 برامج']],
            ['BSH-OV-HBF534', 'فرن Bosch مدمج كهربائي', 'Bosch Built-in Oven', 'HBF534ES0M', 'Bosch', 'أفران وطباخات', 'ضمان الوكيل سنتان', '2000', '2750', '3150', '2500', 'A', 'تركيا', ['السعة' => '66 لتر', 'النوع' => 'مدمج']],
            ['ARI-WH-80', 'سخان مياه Ariston 80 لتر', 'Ariston Water Heater 80L', 'PRO1-R80', 'Ariston', 'سخانات مياه', 'ضمان الوكيل سنتان', '620', '880', '1000', '800', 'B', 'إيطاليا', ['السعة' => '80 لتر', 'القدرة' => '1500 واط']],
            ['ARI-WH-50', 'سخان مياه Ariston 50 لتر', 'Ariston Water Heater 50L', 'PRO1-R50', 'Ariston', 'سخانات مياه', 'ضمان الوكيل سنة واحدة', '470', '680', '780', '620', 'B', 'إيطاليا', ['السعة' => '50 لتر']],
            ['BEK-REF-RDNE', 'ثلاجة Beko 450 لتر', 'Beko Refrigerator 450L', 'RDNE450K02DX', 'Beko', 'ثلاجات وفريزرات', 'ضمان الوكيل سنتان', '2100', '2900', '3300', '2600', 'A+', 'تركيا', ['السعة' => '450 لتر']],
            ['BEK-WM-WTV81', 'غسالة Beko 8 كغم', 'Beko Washing Machine 8kg', 'WTV8612XS', 'Beko', 'غسالات ملابس', 'ضمان الوكيل سنة واحدة', '1500', '2100', '2400', '1900', 'A++', 'تركيا', ['السعة' => '8 كغم']],
            ['BEK-CK-FSE64', 'طباخ Beko غاز 4 عيون', 'Beko Gas Cooker 60cm', 'FSE64110GW', 'Beko', 'أفران وطباخات', 'ضمان الوكيل سنة واحدة', '1250', '1750', '2000', '1580', 'A', 'تركيا', ['العرض' => '60 سم', 'العيون' => '4']],
            ['TOS-REF-GR', 'ثلاجة Toshiba 553 لتر', 'Toshiba Refrigerator 553L', 'GR-RF610WE', 'Toshiba', 'ثلاجات وفريزرات', 'ضمان الكمبروسر 5 سنوات', '3000', '4100', '4650', '3700', 'A+', 'تايلند', ['السعة' => '553 لتر']],
            ['MID-AC-MSA12', 'مكيف Midea 1 طن', 'Midea Split AC 12000BTU', 'MSAGBU-12HRFN1', 'Midea', 'مكيفات', 'ضمان الكمبروسر 5 سنوات', '1700', '2350', '2700', '2150', 'A+', 'الصين', ['القدرة' => '12000 BTU']],
            ['HIS-AC-AS24', 'مكيف Hisense 2 طن', 'Hisense Split AC 24000BTU', 'AS-24UR4SVETG', 'Hisense', 'مكيفات', 'ضمان الكمبروسر 5 سنوات', '2900', '3900', '4450', '3550', 'A++', 'الصين', ['القدرة' => '24000 BTU']],
            ['HIS-TV-55A6', 'شاشة Hisense 55 بوصة سمارت', 'Hisense Smart TV 55"', '55A6K', 'Hisense', 'أجهزة مطبخ صغيرة', 'ضمان الوكيل سنة واحدة', '1400', '1950', '2250', '1780', 'A', 'الصين', ['الحجم' => '55 بوصة', 'الدقة' => '4K']],
            ['PAN-VC-MC', 'مكنسة Panasonic 2000 واط', 'Panasonic Vacuum Cleaner', 'MC-CG713', 'Panasonic', 'مكانس كهربائية', 'ضمان الوكيل سنة واحدة', '430', '620', '710', '560', 'B', 'ماليزيا', ['القدرة' => '2000 واط']],
            ['NAT-BL-HB', 'خلاط National 600 واط', 'National Blender 600W', 'NB-600X', 'National', 'أجهزة مطبخ صغيرة', 'ضمان الوكيل سنة واحدة', '160', '250', '290', '220', 'B', 'الصين', ['القدرة' => '600 واط']],
        ];

        return array_map(fn ($r) => ['sku' => $r[0], 'name' => $r[1], 'en' => $r[2], 'model' => $r[3], 'brand' => $r[4], 'category' => $r[5], 'warranty' => $r[6],
            'cost' => $r[7], 'cash' => $r[8], 'installment' => $r[9], 'minimum' => $r[10], 'energy' => $r[11], 'origin' => $r[12], 'specs' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v], array_keys($r[13]), $r[13]),
            'barcode' => strtoupper(substr(md5($r[0]), 0, 12))], $rows);
    }

    /** Products a supplier delivers, by brand. */
    private function supplierProducts(array $supplier): array
    {
        return array_values(array_filter($this->products, fn (Product $p) => in_array(DB::table('brands')->where('id', $p->brand_id)->value('name_ar'), $supplier['brands'], true)));
    }

    private function purchasing(int $month): void
    {
        // Unique per run, so a repeat run never collides with serials already in stock.
        $serial = 1000 + (int) DB::table('serial_numbers')->count() * 10;
        {
            foreach ($this->suppliers as $supplier) {
                if ($month % 2 === 0 && $supplier['currency'] === 'USD') {
                    continue; // Importers deliver every other month.
                }
                $catalogue = $this->supplierProducts($supplier);
                if (! $catalogue) {
                    continue;
                }
                $orderDate = sprintf('2026-%02d-03', $month);
                $lines = [];
                foreach (array_slice($catalogue, 0, 3) as $product) {
                    $quantity = (string) mt_rand(2, 5);
                    $unitPrice = $supplier['currency'] === 'USD'
                        ? (string) round((float) $product->standard_cost / 3.7, 2)
                        : (string) $product->standard_cost;
                    $lines[] = ['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'discount_amount' => '0', 'tax_code_id' => null, 'tax_inclusive' => false];
                }
                $order = app(SavePurchaseOrder::class)->execute(['supplier_id' => $supplier['id'], 'document_date' => $orderDate, 'currency' => $supplier['currency'], 'expected_date' => sprintf('2026-%02d-10', $month), 'notes' => 'طلبية شهرية', 'lines' => $lines], $this->actor->id);
                $order = app(PurchaseOrderWorkflow::class)->submit($order->id, $order->version, $this->actor->id);
                $order = app(PurchaseOrderWorkflow::class)->decide($order->id, $order->version, 'approved', 'مراجعة الأسعار والكميات مع المورد', $this->actor->id);
                $order = app(PurchaseOrderWorkflow::class)->issue($order->id, $order->version, $this->actor->id);
                $this->counts['purchase_orders']++;

                $receiptLines = [];
                foreach ($order->lines as $line) {
                    $product = Product::find($line->product_id);
                    $serials = [];
                    for ($i = 0; $i < (int) $line->quantity; $i++) {
                        $serials[] = strtoupper(substr($product->sku, 0, 6)).'-'.(++$serial);
                    }
                    $receiptLines[] = ['product_id' => $line->product_id, 'purchase_order_line_id' => $line->id, 'location_id' => $this->warehouse, 'condition' => 'new', 'quantity' => (string) (int) $line->quantity, 'serials' => $serials];
                }
                $receipt = app(SaveGoodsReceipt::class)->execute(['supplier_id' => $supplier['id'], 'purchase_order_id' => $order->id, 'document_date' => sprintf('2026-%02d-08', $month), 'delivery_reference' => 'DN-'.$month.'-'.$supplier['id'], 'currency' => $supplier['currency'], 'lines' => $receiptLines], $this->actor->id);
                $receipt = app(PostGoodsReceipt::class)->execute($receipt->id, $receipt->version, $this->actor->id);
                $this->counts['receipts']++;

                $invoiceLines = [];
                foreach ($receipt->lines as $line) {
                    $invoiceLines[] = ['goods_receipt_line_id' => $line->id, 'quantity' => (string) (int) $line->quantity, 'unit_price' => (string) $line->unit_cost, 'discount_amount' => '0', 'tax_code_id' => $this->vat, 'tax_inclusive' => false, 'tax_recoverable' => true];
                }
                $invoice = app(SaveSupplierInvoice::class)->execute(['supplier_id' => $supplier['id'], 'supplier_invoice_no' => sprintf('INV-%d-%02d-%d', $supplier['id'], $month, $receipt->id), 'invoice_date' => sprintf('2026-%02d-09', $month), 'posting_date' => sprintf('2026-%02d-10', $month), 'due_date' => sprintf('2026-%02d-28', $month), 'currency' => $supplier['currency'], 'lines' => $invoiceLines], $this->actor->id);
                $invoice = app(PostSupplierInvoice::class)->execute($invoice->id, $invoice->version, $this->actor->id);
                $this->counts['supplier_invoices']++;

                if ($month <= 8) {
                    $payment = app(PaySupplier::class)->save(['supplier_id' => $supplier['id'], 'document_date' => sprintf('2026-%02d-25', $month), 'currency' => $supplier['currency'], 'amount' => (string) $invoice->foreign_total, 'method' => $supplier['currency'] === 'ILS' ? 'cash' : 'bank', 'cashbox_id' => $this->cashbox, 'bank_account_id' => $supplier['currency'] === 'USD' ? $this->bankUsd : $this->bank, 'reason' => 'سداد فاتورة المورد '.$invoice->supplier_invoice_no, 'payment_reference' => 'TR-'.$invoice->supplier_invoice_no, 'allocations' => [['supplier_invoice_id' => $invoice->id, 'amount' => (string) $invoice->foreign_total]]], $this->actor->id);
                    app(PaySupplier::class)->post($payment->id, $this->actor->id);
                    $this->counts['supplier_payments']++;
                }
            }
        }
    }

    private function customers(): void
    {
        $names = [
            ['أحمد محمود الشريف', 'رام الله - بيتونيا', 'معلم مدرسة', '0599111222', '5000'],
            ['فاطمة حسن دراغمة', 'نابلس - رفيديا', 'موظفة بنك', '0598222333', '8000'],
            ['محمد سليم الحاج', 'الخليل - وادي الهرية', 'صاحب محل', '0597333444', '12000'],
            ['سارة عماد قاسم', 'بيت لحم - بيت جالا', 'ممرضة', '0596444555', '6000'],
            ['خالد يوسف عودة', 'جنين - المخيم', 'سائق', '0595555666', '4000'],
            ['ليلى ناصر الشوبكي', 'طولكرم - المركز', 'محاسبة', '0594666777', '9000'],
            ['عمر فؤاد زيدان', 'رام الله - المصيون', 'مهندس', '0593777888', '15000'],
            ['هدى سامي برهم', 'قلقيلية - المدينة', 'ربة منزل', '0592888999', '3500'],
            ['نضال رائد أبو سنينة', 'الخليل - الحرس', 'تاجر', '0591999000', '20000'],
            ['ريم وليد الطويل', 'أريحا - المركز', 'صيدلانية', '0590111333', '7000'],
            ['باسل منير خضر', 'سلفيت - المدينة', 'موظف حكومي', '0599222444', '5500'],
            ['نور الدين صالح', 'طوباس - المركز', 'مزارع', '0598333555', '4500'],
        ];
        foreach ($names as $index => [$name, $address, $work, $phone, $limit]) {
            $customer = Customer::create(['code' => sprintf('CUS-%03d', $index + 1), 'name' => $name, 'identity_number' => (string) (900000000 + $index * 137), 'phone' => $phone, 'address' => $address, 'workplace' => $work,
                'currency' => 'ILS', 'credit_limit' => $limit, 'max_active_contracts' => 2, 'max_overdue_days' => 30, 'risk_flag' => 'normal', 'is_walk_in' => false, 'active' => true, 'version' => 1, 'created_by' => $this->actor->id]);
            $this->customers[$index] = ['id' => $customer->id, 'name' => $name];
            $this->counts['customers']++;
        }
        $walkIn = Customer::create(['code' => 'CUS-WALKIN', 'name' => 'عميل نقدي عابر', 'currency' => 'ILS', 'credit_limit' => '0', 'max_active_contracts' => 0, 'max_overdue_days' => 0, 'risk_flag' => 'normal', 'is_walk_in' => true, 'active' => true, 'version' => 1, 'created_by' => $this->actor->id]);
        $this->customers['walkin'] = ['id' => $walkIn->id, 'name' => 'عميل نقدي عابر'];
        $this->counts['customers']++;
    }

    /** One available serial for a product, so sales consume real stock. */
    private function takeSerial(int $productId): ?array
    {
        $row = DB::table('serial_numbers')->where('product_id', $productId)->where('status', 'in_stock')->orderBy('id')->first();
        if (! $row) {
            return null;
        }
        $location = StockLocation::find($row->current_location_id);

        return $location?->sellable ? ['serial' => $row->serial_no, 'location_id' => $row->current_location_id] : null;
    }

    private function sales(int $month): void
    {
        $skus = array_keys($this->products);
        $checkNo = 4500 + $month * 40;
        {
            $salesThisMonth = 6;
            for ($i = 0; $i < $salesThisMonth; $i++) {
                $day = 12 + $i * 2;
                $date = sprintf('2026-%02d-%02d', $month, $day);
                $product = $this->products[$skus[($month * 7 + $i * 3) % count($skus)]];
                $stock = $this->takeSerial($product->id);
                if (! $stock) {
                    continue;
                }
                $installment = $i % 3 === 0;
                $named = array_values(array_intersect_key($this->customers, array_flip(array_filter(array_keys($this->customers), 'is_int'))));
                $customer = $installment
                    ? $named[($month + $i) % count($named)]
                    : ($i % 2 === 0 ? $this->customers['walkin'] : $named[($month * 2 + $i) % count($named)]);
                $unitPrice = $installment ? (string) $product->installment_price : (string) $product->cash_price;
                $lines = [['product_id' => $product->id, 'location_id' => $stock['location_id'], 'quantity' => '1', 'unit_price' => $unitPrice, 'discount_amount' => '0', 'tax_code_id' => $this->vat, 'tax_inclusive' => true, 'serials' => [$stock['serial']]]];
                $checkout = $installment
                    ? ['amount' => (string) round((float) $unitPrice * 0.25, 2), 'method' => 'cash', 'cashbox_id' => $this->cashbox, 'bank_account_id' => null, 'installment_count' => 6, 'first_due_date' => date('Y-m-d', strtotime($date.' +1 month')), 'frequency' => 'monthly', 'terms' => 'دفعات شهرية متساوية']
                    : ['amount' => '0', 'method' => 'cash', 'cashbox_id' => $this->cashbox, 'bank_account_id' => null];
                $invoice = app(SaveSalesInvoice::class)->execute([
                    'customer_id' => $customer['id'], 'document_date' => $date, 'due_date' => $installment ? date('Y-m-d', strtotime($date.' +6 months')) : $date,
                    'currency' => 'ILS', 'sale_mode' => $installment ? 'installment' : 'cash', 'checkout' => $checkout,
                    'delivery_address' => null, 'delivery_date' => $date, 'notes' => null, 'lines' => $lines,
                ], $this->actor->id);
                // Same route as the screens: a sale that trips a credit rule is submitted,
                // approved and only then posted.
                if ($installment) {
                    $this->clearCredit($invoice);
                }
                app(PostSalesInvoice::class)->execute($invoice->id, $this->actor->id);
                $this->counts['sales']++;
                if ($installment) {
                    $this->counts['installment_sales']++;
                    $this->collect($invoice->fresh(), $customer, $month, $checkNo);
                }
            }
        }
    }

    private function clearCredit(object $invoice): void
    {
        $customer = Customer::findOrFail($invoice->customer_id);
        $result = app(\App\Domains\Installments\Actions\CreditDecision::class)->evaluate($invoice, $customer);
        if (! $result['reasons']) {
            return;
        }
        $approval = app(\App\Domains\Approvals\Actions\BusinessApproval::class)->request('sales_credit', $invoice->id, $invoice->version, 'installments', $result['payload'], 'مراجعة ائتمانية: '.implode('، ', $result['reasons']), $this->actor->id);
        app(\App\Domains\Approvals\Actions\BusinessApproval::class)->decide($approval, 'approved', 'اعتماد البيع بالتقسيط بعد مراجعة وضع العميل', $this->actor->id);
        $invoice->update(['credit_approval_id' => $approval]);
        $this->counts['credit_approvals'] = ($this->counts['credit_approvals'] ?? 0) + 1;
    }

    /** Installment collections: cash for some months, a check for others. */
    private function collect(object $invoice, array $customer, int $month, int &$checkNo): void
    {
        $contract = DB::table('installment_contracts')->where('sales_invoice_id', $invoice->id)->first();
        if (! $contract) {
            return;
        }
        $due = DB::table('installment_schedule')->where('contract_id', $contract->id)->where('superseded', false)->orderBy('due_date')->get();
        foreach ($due as $index => $row) {
            if ($row->due_date > '2026-09-20') {
                break; // Later instalments stay open, so the ageing report has content.
            }
            $amount = (string) $row->amount;
            if ($index % 2 === 1) {
                $check = app(ManageCheck::class)->receive([
                    'customer_id' => $customer['id'], 'check_no' => (string) (++$checkNo), 'bank_name' => ['بنك فلسطين', 'البنك العربي', 'بنك القدس'][$checkNo % 3],
                    'bank_branch' => 'الفرع الرئيسي', 'payer_name' => $customer['name'], 'account_reference' => 'ACC-'.(1000 + $checkNo),
                    'currency' => 'ILS', 'amount' => $amount, 'issue_date' => $row->due_date, 'due_date' => $row->due_date, 'received_date' => $row->due_date,
                    'notes' => 'شيك قسط', 'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => $amount]],
                ], $this->actor->id);
                $this->counts['checks']++;
                $depositDate = date('Y-m-d', strtotime($row->due_date.' +2 days'));
                if ($depositDate <= '2026-09-20') {
                    app(ManageCheck::class)->deposit(['check_ids' => [$check->id], 'bank_account_id' => $this->bank, 'document_date' => $depositDate, 'reason' => 'إيداع شيكات التحصيل'], $this->actor->id);
                    $clearDate = date('Y-m-d', strtotime($row->due_date.' +5 days'));
                    if ($clearDate <= '2026-09-20') {
                        // Every fifth check bounces, so the follow-up screens have real cases.
                        $event = $checkNo % 5 === 0 ? 'bounce' : 'clear';
                        app(ManageCheck::class)->transition($check->id, $event, ['document_date' => $clearDate, 'reason' => $event === 'bounce' ? 'رصيد غير كاف لدى الساحب' : 'تحصيل الشيك في موعده', 'fees' => $event === 'bounce' ? '25' : '0'], $this->actor->id);
                    }
                }

                continue;
            }
            $payment = app(ReceiveCustomerPayment::class)->save([
                'customer_id' => $customer['id'], 'document_date' => $row->due_date, 'currency' => 'ILS', 'amount' => $amount,
                'method' => 'cash', 'cashbox_id' => $this->cashbox, 'bank_account_id' => null, 'payment_reference' => 'قسط '.($index + 1),
                'notes' => null, 'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => $amount]],
            ], $this->actor->id);
            app(ReceiveCustomerPayment::class)->post($payment->id, $this->actor->id);
            $this->counts['payments']++;
        }
    }

    private function expenses(int $month): void
    {
        $account = DB::table('accounts')->where('account_type', 'expense')->where('is_control_account', false)->where('active', true)->orderBy('code')->first();
        if (! $account) {
            return;
        }
        {
            foreach ([['إيجار المحل', '2500'], ['فاتورة كهرباء', '850'], ['رواتب الموظفين', '6000']] as [$description, $amount]) {
                $expense = app(ManageExpense::class)->save([
                    'document_date' => sprintf('2026-%02d-27', $month), 'currency' => 'ILS', 'amount' => $amount, 'account_id' => $account->id,
                    'tax_code_id' => $this->vat, 'tax_inclusive' => true, 'tax_recoverable' => true, 'description' => $description.' - شهر '.$month,
                    'method' => 'cash', 'cashbox_id' => $this->cashbox, 'bank_account_id' => null, 'reference' => null, 'beneficiary' => null,
                ], $this->actor->id);
                // The treasury policy requires a decision on every expense before posting.
                if ($expense->approval_id) {
                    app(\App\Domains\Approvals\Actions\BusinessApproval::class)->decide($expense->approval_id, 'approved', 'مصروف تشغيلي شهري معتمد', $this->actor->id);
                }
                app(ManageExpense::class)->post($expense->id, $this->actor->id);
                $this->counts['expenses']++;
            }
        }
    }
}
