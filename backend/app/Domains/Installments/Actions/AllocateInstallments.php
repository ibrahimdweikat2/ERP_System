<?php
namespace App\Domains\Installments\Actions;
use App\Support\Decimal;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;
class AllocateInstallments {
    /** Oldest installment first; a check tied to one installment ($scheduleId) settles that one first, then any excess continues oldest first. */
    public function payment(int $allocationId,int $invoiceId,string $amount,string $date,?int $scheduleId=null):void {
        $contract=DB::table('installment_contracts')->where('sales_invoice_id',$invoiceId)->lockForUpdate()->first();if(!$contract)return;
        $left=$amount;
        $rows=DB::table('installment_schedule')->where('contract_id',$contract->id)->where('superseded',false)->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
        if($scheduleId!==null){
            if(!$rows->contains('id',$scheduleId))throw new BusinessException('INSTALLMENT_TARGET_INVALID','القسط المختار لا يتبع هذا العقد أو استُبدل بجدول أحدث.');
            $rows=$rows->sortBy(fn($row)=>$row->id===$scheduleId?0:1)->values();
        }
        foreach($rows as $row){
            $remaining=Decimal::sub(Decimal::sub($row->amount,$row->paid_amount),$row->adjustment_amount);
            $take=Decimal::cmp($left,$remaining)<0?$left:$remaining;if(Decimal::cmp($take,'0')<=0)continue;
            DB::table('installment_allocations')->insert(['payment_allocation_id'=>$allocationId,'schedule_id'=>$row->id,'amount'=>$take,'created_at'=>now()]);
            DB::table('installment_schedule')->where('id',$row->id)->update(['paid_amount'=>Decimal::add($row->paid_amount,$take),'updated_at'=>now()]);$left=Decimal::sub($left,$take);
        }
        if(Decimal::cmp($left,'0')!==0)throw new BusinessException('SCHEDULE_ALLOCATION_MISMATCH','المبلغ يتجاوز الأقساط المتبقية.');
        $this->refresh($contract->id);
    }
    public function credit(int $invoiceId,string $amount,string $source,int $sourceId):void {
        $contract=DB::table('installment_contracts')->where('sales_invoice_id',$invoiceId)->lockForUpdate()->first();if(!$contract)return;
        $left=$amount;
        foreach(DB::table('installment_schedule')->where('contract_id',$contract->id)->where('superseded',false)->orderByDesc('due_date')->orderByDesc('id')->lockForUpdate()->get() as $row){
            $remaining=Decimal::sub(Decimal::sub($row->amount,$row->paid_amount),$row->adjustment_amount);$take=Decimal::cmp($left,$remaining)<0?$left:$remaining;
            if(Decimal::cmp($take,'0')<=0)continue;
            DB::table('installment_adjustments')->insert(['schedule_id'=>$row->id,'source_type'=>$source,'source_id'=>$sourceId,'amount'=>$take,'created_at'=>now()]);
            DB::table('installment_schedule')->where('id',$row->id)->update(['adjustment_amount'=>Decimal::add($row->adjustment_amount,$take),'updated_at'=>now()]);$left=Decimal::sub($left,$take);
        }
        // Any excess is an explicit customer credit/refund, outside the schedule.
        $this->refresh($contract->id);
    }
    public function refresh(int $id):void {
        $remaining=DB::table('installment_schedule')->where('contract_id',$id)->where('superseded',false)->selectRaw('COALESCE(SUM(amount-paid_amount-adjustment_amount),0) AS amount')->first()->amount;
        DB::table('installment_contracts')->where('id',$id)->update(['status'=>Decimal::cmp($remaining,'0')===0?'completed':'active','updated_at'=>now()]);
    }
}
