<?php
namespace App\Domains\CashBank\Actions;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class CaptureTreasuryMovements {
    public function execute(JournalEntry $entry,int $actor):void {
        if(!Schema::hasTable('treasury_movements'))return;
        $policy=app(BusinessApproval::class)->policy('treasury');
        foreach($entry->lines as $line){
            $cash=DB::table('cashboxes')->where('account_id',$line->account_id)->lockForUpdate()->first();$bank=DB::table('bank_accounts')->where('account_id',$line->account_id)->lockForUpdate()->first();if(!$cash&&!$bank)continue;
            $master=$cash??$bank;if($line->currency!==$master->currency_code)throw new BusinessException('TREASURY_CURRENCY_MISMATCH','عملة القيد لا تطابق عملة الصندوق أو البنك.');
            $session=$cash?DB::table('cashier_sessions')->where('cashbox_id',$cash->id)->where('status','open')->lockForUpdate()->first():null;
            if($cash && $policy['enforce_sessions'] && (!$session || $session->opened_on>$entry->entry_date))throw new BusinessException('CASH_SESSION_REQUIRED','افتح وردية للصندوق قبل تسجيل حركة نقدية.');
            if($session && $entry->entry_date<$session->opened_on)throw new BusinessException('CASH_SESSION_BACKDATE','لا يمكن إدخال حركة قبل بداية الوردية المفتوحة.');
            DB::table('treasury_movements')->insert(['journal_line_id'=>$line->id,'account_id'=>$line->account_id,'cashbox_id'=>$cash?->id,'bank_account_id'=>$bank?->id,'session_id'=>$session?->id,'document_date'=>$entry->entry_date,'currency'=>$line->currency,'amount'=>$line->foreign_amount,'base_amount'=>Decimal::sub($line->debit,$line->credit),'created_by'=>$actor,'created_at'=>now()]);
        }
    }
}
