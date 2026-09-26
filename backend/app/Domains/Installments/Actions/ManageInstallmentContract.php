<?php
namespace App\Domains\Installments\Actions;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\SalesInvoice;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
class ManageInstallmentContract {
    public function fromSale(SalesInvoice $sale,int $actor):int {
        $existing=DB::table('installment_contracts')->where('sales_invoice_id',$sale->id)->lockForUpdate()->first();if($existing)return $existing->id;
        $down=$sale->checkout['amount']??'0';$financed=Decimal::sub($sale->foreign_total,$down);
        $rows=app(GenerateSchedule::class)->execute($financed,$sale->checkout);
        if($rows[0]['due_date']<$sale->document_date)throw new BusinessException('SCHEDULE_DATE_INVALID','أول قسط لا يسبق تاريخ البيع.');
        $cash='0';foreach($sale->lines as $line)$cash=Decimal::add($cash,$line->product_snapshot['cash_reference_total']??Decimal::mul($line->list_price,$line->quantity));
        $id=DB::table('installment_contracts')->insertGetId(['document_no'=>app(NextDocumentNumber::class)->execute('installment_contract',$sale->document_date),'customer_id'=>$sale->customer_id,'sales_invoice_id'=>$sale->id,'document_date'=>$sale->document_date,'currency'=>$sale->currency,'exchange_rate'=>$sale->exchange_rate,'original_amount'=>$sale->foreign_total,'down_payment'=>$down,'financed_amount'=>$financed,'cash_price'=>$cash,'markup'=>Decimal::sub($sale->foreign_total,$cash),'policy_snapshot'=>json_encode(app(BusinessApproval::class)->policy('installments'),JSON_THROW_ON_ERROR),'terms'=>$sale->checkout['terms']??null,'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        foreach($rows as $row)DB::table('installment_schedule')->insert([...$row,'contract_id'=>$id,'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        app(RecordAudit::class)->execute('installments.activated','installment_contract',$id,null,['sales_invoice_id'=>$sale->id,'financed_amount'=>$financed,'schedule'=>$rows],$actor);
        return $id;
    }
    public function schedule(int $id):array {
        $c=DB::table('installment_contracts')->find($id);abort_unless($c,404);
        $rows=DB::table('installment_schedule')->where('contract_id',$id)->orderBy('version')->orderBy('due_date')->orderBy('id')->get();
        foreach($rows as $row){$row->remaining=Decimal::sub(Decimal::sub($row->amount,$row->paid_amount),$row->adjustment_amount);$row->status=$row->superseded?'superseded':(Decimal::cmp($row->remaining,'0')===0?'paid':($row->due_date<today()->subDays((int)(json_decode($c->policy_snapshot,true)['grace_days']??0))->toDateString()?'overdue':(Decimal::cmp($row->paid_amount,'0')>0?'partial':($row->due_date===today()->toDateString()?'due':'upcoming'))));}
        // Checks that paid each installment (any status, so a bounced one stays visible).
        $checks=DB::table('installment_allocations as i')->join('payment_allocations as p','p.id','=','i.payment_allocation_id')->join('checks as k','k.source_payment_id','=','p.customer_payment_id')
            ->whereIn('i.schedule_id',$rows->pluck('id'))->orderBy('k.due_date')->get(['i.schedule_id','k.id','k.check_no','k.due_date','k.status','i.amount'])->groupBy('schedule_id');
        foreach($rows as $row){$row->checks=($checks[$row->id]??collect())->map(fn($k)=>['id'=>$k->id,'check_no'=>$k->check_no,'due_date'=>$k->due_date,'status'=>$k->status,'amount'=>$k->amount])->values();}
        return ['contract'=>$c,'schedule'=>$rows,'customer'=>Customer::findOrFail($c->customer_id)->identity(),'reschedules'=>DB::table('installment_reschedules')->where('contract_id',$id)->orderByDesc('id')->get()];
    }
    public function requestReschedule(int $id,array $input,int $actor):object {
        return DB::transaction(function()use($id,$input,$actor){
            $c=DB::table('installment_contracts')->where('id',$id)->lockForUpdate()->first();abort_unless($c,404);
            Customer::lockForUpdate()->findOrFail($c->customer_id);
            $old=DB::table('installment_schedule')->where('contract_id',$id)->where('superseded',false)->orderBy('id')->lockForUpdate()->get();$remaining='0';
            foreach($old as $row)$remaining=Decimal::add($remaining,Decimal::sub(Decimal::sub($row->amount,$row->paid_amount),$row->adjustment_amount));
            $rows=app(GenerateSchedule::class)->execute($remaining,$input);
            $requestId=DB::table('installment_reschedules')->insertGetId(['contract_id'=>$id,'old_version'=>$c->schedule_version,'old_schedule'=>json_encode($old,JSON_THROW_ON_ERROR),'new_schedule'=>json_encode($rows,JSON_THROW_ON_ERROR),'reason'=>$input['reason'],'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
            $payload=['contract_id'=>$id,'old_version'=>$c->schedule_version,'old_schedule'=>$old->toArray(),'new_schedule'=>$rows,'reason'=>$input['reason']];
            $approval=app(BusinessApproval::class)->request('installment_reschedule',$requestId,1,'installments',$payload,$input['reason'],$actor);
            DB::table('installment_reschedules')->where('id',$requestId)->update(['approval_id'=>$approval]);
            return DB::table('installment_reschedules')->find($requestId);
        },5);
    }
    public function applyReschedule(int $requestId,int $actor):array {
        return DB::transaction(function()use($requestId,$actor){
            $r=DB::table('installment_reschedules')->where('id',$requestId)->lockForUpdate()->first();abort_unless($r,404);
            $c=DB::table('installment_contracts')->where('id',$r->contract_id)->lockForUpdate()->first();Customer::lockForUpdate()->findOrFail($c->customer_id);
            if($r->status==='applied')return $this->schedule($c->id);
            $current=DB::table('installment_schedule')->where('contract_id',$c->id)->where('superseded',false)->orderBy('id')->lockForUpdate()->get();
            $old=json_decode($r->old_schedule,true,512,JSON_THROW_ON_ERROR);$rows=json_decode($r->new_schedule,true,512,JSON_THROW_ON_ERROR);
            if($c->schedule_version!==$r->old_version || json_encode($current,JSON_THROW_ON_ERROR)!==json_encode($old,JSON_THROW_ON_ERROR))throw new BusinessException('RESCHEDULE_CHANGED','حدث تحصيل أو تعديل بعد طلب الجدولة؛ أنشئ طلباً جديداً.');
            app(BusinessApproval::class)->require($r->approval_id,['contract_id'=>$c->id,'old_version'=>$r->old_version,'old_schedule'=>$old,'new_schedule'=>$rows,'reason'=>$r->reason],'installments');
            DB::table('installment_schedule')->where('contract_id',$c->id)->where('superseded',false)->update(['superseded'=>true,'updated_at'=>now()]);
            foreach($rows as $row)DB::table('installment_schedule')->insert([...$row,'contract_id'=>$c->id,'version'=>$c->schedule_version+1,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('installment_contracts')->where('id',$c->id)->update(['schedule_version'=>$c->schedule_version+1,'status'=>'active','updated_at'=>now()]);
            DB::table('installment_reschedules')->where('id',$requestId)->update(['status'=>'applied','applied_at'=>now(),'updated_at'=>now()]);
            app(RecordAudit::class)->execute('installments.rescheduled','installment_contract',$c->id,null,['request_id'=>$requestId,'version'=>$c->schedule_version+1,'reason'=>$r->reason],$actor);
            return $this->schedule($c->id);
        },5);
    }
}
