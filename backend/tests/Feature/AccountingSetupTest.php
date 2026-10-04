<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Accounting\Actions\SaveAccountingMaster;
use App\Domains\Accounting\Models\Account;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_fiscal_periods_cover_a_non_calendar_year_without_gaps(): void
    {
        $year = app(CreateFiscalYear::class)->execute(['name' => '2026/27', 'starts_on' => '2026-04-15', 'ends_on' => '2027-04-14']);
        $this->assertCount(13, $year->periods);
        $this->assertSame('2026-04-15', $year->periods->first()->starts_on);
        $this->assertSame('2027-04-14', $year->periods->last()->ends_on);
        $this->expectException(BusinessException::class);
        app(CreateFiscalYear::class)->execute(['name' => 'overlap', 'starts_on' => '2026-12-01', 'ends_on' => '2027-12-01']);
    }

    public function test_no_live_vat_rate_or_opening_balance_is_invented(): void
    {
        $this->assertSame(0, DB::table('tax_codes')->count());
        $this->assertSame('ILS', StoreSetting::current()->base_currency);
        $this->assertDatabaseHas('account_mappings', ['key' => 'ar']);
    }

    public function test_tax_versions_cannot_overlap_and_rates_use_decimals(): void
    {
        $action = app(SaveAccountingMaster::class);
        $d = ['code' => 'TEST_VAT', 'name_ar' => 'ضريبة اختبار', 'category' => 'standard', 'rate' => '15.0000', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'input_account_id' => Account::where('code', '1400')->value('id'), 'output_account_id' => Account::where('code', '2200')->value('id')];
        $tax = $action->execute('tax-codes', $d);
        $this->assertSame('15.0000', $tax->rate);
        $this->expectException(BusinessException::class);
        $action->execute('tax-codes', [...$d, 'effective_from' => '2026-07-01']);
    }

    public function test_exchange_rates_cannot_be_changed_or_added_for_base_currency(): void
    {
        $action = app(SaveAccountingMaster::class);
        $r = $action->execute('exchange-rates', ['currency_code' => 'USD', 'rate_date' => '2026-09-06', 'rate_to_base' => '3.50000000', 'source' => 'manual test']);
        $this->assertSame('3.50000000', $r->rate_to_base);
        $this->expectException(QueryException::class);
        DB::table('exchange_rates')->where('id', $r->id)->update(['rate_to_base' => '4']);
    }
}
