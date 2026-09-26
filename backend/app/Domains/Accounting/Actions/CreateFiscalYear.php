<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\FiscalYear;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateFiscalYear
{
    public function execute(array $data): FiscalYear
    {
        return DB::transaction(function () use ($data) {
            StoreSetting::lockForUpdate()->findOrFail(1);
            if (FiscalYear::where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                throw new BusinessException('FISCAL_YEAR_OVERLAP', 'الفترة المختارة تتداخل مع سنة مالية موجودة.');
            }
            $start = CarbonImmutable::parse($data['starts_on']);
            $end = CarbonImmutable::parse($data['ends_on']);
            if ($start->diffInDays($end) > 366) {
                throw new BusinessException('FISCAL_YEAR_TOO_LONG', 'السنة المالية يجب ألا تتجاوز 367 يوماً.');
            }
            $year = FiscalYear::create($data);
            $n = 1;
            while ($start->lessThanOrEqualTo($end)) {
                $periodEnd = $start->endOfMonth()->min($end);
                $year->periods()->create(['period_no' => $n++, 'starts_on' => $start->toDateString(), 'ends_on' => $periodEnd->toDateString(), 'status' => 'open']);
                $start = $periodEnd->addDay()->startOfDay();
            }
            app(RecordAudit::class)->execute('accounting.fiscal_year_created', 'fiscal_year', $year->id, null, $year->toArray());

            return $year->load('periods');
        }, 3);
    }
}
