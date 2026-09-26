<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Audit\Actions\RecordAudit;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

class ChangePeriodState
{
    public function execute(int $id, string $status, string $reason, int $actorId): AccountingPeriod
    {
        return DB::transaction(function () use ($id, $status, $reason, $actorId) {
            $period = AccountingPeriod::lockForUpdate()->findOrFail($id);
            if ($period->status === 'locked' && $status !== 'locked') {
                throw new BusinessException('PERIOD_PERMANENTLY_LOCKED', 'الفترة المقفلة نهائياً لا تُفتح. استخدم تصحيحاً في فترة لاحقة.');
            }
            $before = $period->toArray();
            $period->update(['status' => $status, 'locked_at' => $status === 'open' ? null : now(), 'locked_by' => $status === 'open' ? null : $actorId]);
            app(RecordAudit::class)->execute('accounting.period_changed', 'accounting_period', $id, $before, [...$period->toArray(), 'reason' => $reason], $actorId);

            return $period;
        }, 3);
    }
}
