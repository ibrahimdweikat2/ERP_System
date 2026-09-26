<?php

namespace App\Domains\Checks\Actions;

use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * Receives a book of post-dated checks for an installment contract in one step: each
 * check is an ordinary check receipt tied to the installment it pays. All checks are
 * recorded together or none are (a duplicate number rejects the whole batch).
 */
class ReceiveInstallmentChecks
{
    /** @return list<int> the new check ids */
    public function execute(int $contractId, array $d, int $actor): array
    {
        return DB::transaction(function () use ($contractId, $d, $actor) {
            $contract = DB::table('installment_contracts')->where('id', $contractId)->lockForUpdate()->first();
            abort_unless($contract, 404);
            if ($contract->status !== 'active') {
                throw new BusinessException('CONTRACT_NOT_ACTIVE', 'العقد مكتمل أو غير نشط؛ لا أقساط لاستلام شيكات لها.');
            }
            $ids = [];
            foreach ($d['checks'] as $row) {
                $ids[] = app(ManageCheck::class)->receive([
                    'customer_id' => $contract->customer_id, 'check_no' => $row['check_no'], 'bank_name' => $d['bank_name'], 'bank_branch' => $d['bank_branch'] ?? null,
                    'payer_name' => $d['payer_name'], 'account_reference' => $d['account_reference'], 'currency' => $contract->currency, 'amount' => $row['amount'],
                    'issue_date' => $row['issue_date'] ?? min($d['received_date'], $row['due_date']), 'received_date' => $d['received_date'], 'due_date' => $row['due_date'],
                    'notes' => $d['notes'] ?? null,
                    'allocations' => [['sales_invoice_id' => $contract->sales_invoice_id, 'amount' => $row['amount'], 'installment_schedule_id' => $row['installment_schedule_id'] ?? null]],
                ], $actor)->id;
            }

            return $ids;
        }, 5);
    }
}
