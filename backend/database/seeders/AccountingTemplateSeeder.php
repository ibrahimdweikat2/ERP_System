<?php

namespace Database\Seeders;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Journal;
use App\Domains\StoreSetup\Models\Currency;
use App\Domains\StoreSetup\Models\StoreSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountingTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ILS' => 'شيكل إسرائيلي', 'USD' => 'دولار أمريكي', 'JOD' => 'دينار أردني'] as $code => $name) {
            Currency::firstOrCreate(['code' => $code], ['name' => $name, 'decimal_places' => 2, 'is_active' => true, 'is_base' => $code === 'ILS']);
        }
        StoreSetting::query()->firstOrCreate([]);
        $accounts = [
            ['1100', 'النقد في الصندوق', 'Cash on hand', 'asset', false, true, 'cash'],
            ['1110', 'الحساب البنكي', 'Bank', 'asset', false, true, 'bank'],
            ['1120', 'شيكات آجلة برسم التحصيل', 'Post-dated checks', 'asset', true, false, 'checks_receivable'],
            ['1130', 'شيكات تحت التحصيل', 'Checks under collection', 'asset', true, false, 'checks_collection'],
            ['1200', 'ذمم العملاء', 'Accounts receivable', 'asset', true, false, 'ar'],
            ['1300', 'مخزون الأجهزة', 'Inventory', 'asset', true, false, 'inventory'],
            ['1400', 'ضريبة مدخلات', 'Input VAT', 'asset', true, false, 'vat_input'],
            ['1500', 'مصروفات مدفوعة مقدماً', 'Prepayments', 'asset', false, true, null],
            ['1600', 'الأصول الثابتة', 'Fixed assets', 'asset', false, true, null],
            ['2100', 'ذمم الموردين', 'Accounts payable', 'liability', true, false, 'ap'],
            ['2200', 'ضريبة مخرجات', 'Output VAT', 'liability', true, false, 'vat_output'],
            ['2210', 'ضريبة مستحقة', 'VAT payable', 'liability', true, false, 'vat_payable'],
            ['2300', 'بضاعة مستلمة غير مفوترة', 'Goods received not invoiced', 'liability', true, false, 'grni'],
            ['3100', 'رأس مال المالك', 'Owner capital', 'equity', false, true, 'capital'],
            ['3200', 'مسحوبات المالك', 'Owner drawings', 'equity', false, true, 'drawings'],
            ['3300', 'أرباح محتجزة', 'Retained earnings', 'equity', false, true, 'retained_earnings'],
            ['3400', 'تسوية الأرصدة الافتتاحية', 'Opening balance clearing', 'equity', true, false, 'opening'],
            ['4100', 'مبيعات الأجهزة', 'Appliance sales', 'revenue', false, true, 'sales'],
            ['4200', 'إيراد فرق التقسيط', 'Installment markup revenue', 'revenue', false, true, 'installment_revenue'],
            ['4300', 'مردودات المبيعات', 'Sales returns', 'revenue', false, true, 'sales_returns'],
            ['5100', 'تكلفة البضاعة المباعة', 'Cost of goods sold', 'expense', true, false, 'cogs'],
            ['5200', 'فروق وتسويات المخزون', 'Inventory adjustments', 'expense', false, true, 'inventory_adjustment'],
            ['6100', 'إيجار', 'Rent', 'expense', false, true, null],
            ['6200', 'كهرباء', 'Electricity', 'expense', false, true, null],
            ['6300', 'رسوم بنكية', 'Bank fees', 'expense', false, true, 'bank_fees'],
            ['6400', 'مصروفات تشغيلية أخرى', 'Other operating expenses', 'expense', false, true, 'expense'],
            ['6500', 'فروق العملات', 'Exchange gain or loss', 'expense', false, true, 'exchange_difference'],
            ['6600', 'فروق الصندوق', 'Cash variance', 'expense', false, true, 'cash_variance'],
        ];
        foreach ($accounts as [$code,$ar,$en,$type,$control,$manual,$key]) {
            $account = Account::firstOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en, 'account_type' => $type, 'normal_balance' => in_array($type, ['asset', 'expense']) || in_array($code, ['3200', '4300']) ? 'debit' : 'credit', 'is_control_account' => $control, 'allow_manual_posting' => $manual, 'active' => true]);
            if ($key) {
                DB::table('account_mappings')->insertOrIgnore(['key' => $key, 'account_id' => $account->id]);
            }
        }
        foreach (['general' => 'اليومية العامة', 'sales' => 'المبيعات', 'purchases' => 'المشتريات', 'receipts' => 'المقبوضات', 'payments' => 'المدفوعات', 'bank' => 'البنوك', 'inventory' => 'المخزون', 'checks' => 'الشيكات', 'opening' => 'الأرصدة الافتتاحية'] as $code => $name) {
            Journal::firstOrCreate(['code' => $code], ['name_ar' => $name, 'active' => true]);
        }
    }
}
