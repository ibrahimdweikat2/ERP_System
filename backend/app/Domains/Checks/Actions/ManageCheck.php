<?php
namespace App\Domains\Checks\Actions;
use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Actions\CustomerLedger;
use App\Domains\Payments\Actions\ReceiveCustomerPayment;
use App\Domains\Payments\Models\CustomerPayment;
use App\Domains\Installments\Actions\AllocateInstallments;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;

class ManageCheck {
    public function receive(array $d,int $actor):object {
        return DB::transaction(function()use($d,$actor){
            Customer::lockForUpdate()->findOrFail($d['customer_id']);
            $identity=hash('sha256',mb_strtoupper(trim($d['bank_name']).'|'.trim($d['account_reference']).'|'.trim($d['check_no'])));
            if(DB::table('checks')->where('identity_hash',$identity)->lockForUpdate()->first())throw new BusinessException('CHECK_DUPLICATE','الشيك مسجل لنفس البنك والحساب والرقم.',409);
            $payment=app(ReceiveCustomerPayment::class)->save(['customer_id'=>$d['customer_id'],'document_date'=>$d['received_date'],'currency'=>$d['currency'],'amount'=>$d['amount'],'method'=>'check','payment_reference'=>$d['check_no'],'allocations'=>$d['allocations']??[],'notes'=>$d['notes']??null],$actor);
            app(ReceiveCustomerPayment::class)->post($payment->id,$actor,Posting::account('checks_receivable','asset'));
            $id=DB::table('checks')->insertGetId(['check_no'=>trim($d['check_no']),'bank_name'=>trim($d['bank_name']),'bank_branch'=>$d['bank_branch']??null,'payer_name'=>$d['payer_name'],'account_reference'=>trim($d['account_reference']),'identity_hash'=>$identity,'customer_id'=>$d['customer_id'],'source_payment_id'=>$payment->id,'currency'=>$payment->currency,'amount'=>$payment->amount,'base_amount'=>$payment->base_amount,'exchange_rate'=>$payment->exchange_rate,'issue_date'=>$d['issue_date'],'due_date'=>$d['due_date'],'received_date'=>$d['received_date'],'last_event_date'=>$d['received_date'],'created_by'=>$actor,'notes'=>$d['notes']??null,'created_at'=>now(),'updated_at'=>now()]);
            $this->history($id,null,'received',$d['received_date'],'استلام الشيك',CustomerPayment::findOrFail($payment->id)->posted_journal_entry_id,$actor);
            return DB::table('checks')->find($id);
        },5);
    }
    public function deposit(array $d,int $actor):object {
        return DB::transaction(function()use($d,$actor){
            Posting::period($d['document_date']);$p=app(BusinessApproval::class)->policy('checks');
            $checks=DB::table('checks')->whereIn('id',$d['check_ids'])->orderBy('id')->lockForUpdate()->get();
            if($checks->count()!==count($d['check_ids']))throw new BusinessException('CHECK_NOT_FOUND','تعذر العثور على بعض الشيكات.');
            $currency=$checks->first()->currency;Posting::treasury('bank',$d['bank_account_id'],$currency);$total='0';$base='0';
            foreach($checks as $c){if($c->status!=='received'||$c->currency!==$currency||$d['document_date']<$c->last_event_date||(!$p['allow_early_deposit']&&$c->due_date>$d['document_date']))throw new BusinessException('CHECK_NOT_DEPOSITABLE','اختر شيكات مستلمة مؤهلة للإيداع بالعملة نفسها.');$total=Decimal::add($total,$c->amount);$base=Decimal::add($base,$c->base_amount);}
            $id=DB::table('check_deposit_batches')->insertGetId(['document_no'=>app(NextDocumentNumber::class)->execute('check_deposit',$d['document_date']),'document_date'=>$d['document_date'],'bank_account_id'=>$d['bank_account_id'],'currency'=>$currency,'amount'=>$total,'base_amount'=>$base,'created_by'=>$actor,'created_at'=>now()]);
            foreach($checks as $c){
                $entry=app(PostSystemJournal::class)->execute('check',$c->id,'deposit',$d['document_date'],'إيداع شيك '.$c->check_no,[Posting::line('checks_collection',$c->base_amount,$c->amount),Posting::line('checks_receivable',Decimal::sub('0',$c->base_amount),Decimal::sub('0',$c->amount))],$actor,'checks',$this->fx($c));
                DB::table('check_deposit_batch_items')->insert(['batch_id'=>$id,'check_id'=>$c->id,'amount'=>$c->amount,'base_amount'=>$c->base_amount]);
                DB::table('checks')->where('id',$c->id)->update(['status'=>'deposited','deposit_batch_id'=>$id,'bank_account_id'=>$d['bank_account_id'],'deposited_on'=>$d['document_date'],'last_event_date'=>$d['document_date'],'updated_at'=>now()]);
                $this->history($c->id,$c->status,'deposited',$d['document_date'],$d['reason'],$entry->id,$actor);
            }
            return DB::table('check_deposit_batches')->find($id);
        },5);
    }
    public function transition(int $id,string $event,array $d,int $actor):object {
        return DB::transaction(function()use($id,$event,$d,$actor){
            $c=DB::table('checks')->where('id',$id)->lockForUpdate()->first();abort_unless($c,404);Customer::lockForUpdate()->findOrFail($c->customer_id);
            $target=match($event){'clear'=>'cleared','bounce'=>'bounced','collect'=>'under_collection','return'=>'returned',default=>throw new BusinessException('CHECK_EVENT_INVALID','عملية غير مدعومة.')};
            if($c->status===$target)return $c;
            $eligible=match($event){'collect'=>['deposited'],'return'=>['received'],default=>['deposited','under_collection']};
            if(!in_array($c->status,$eligible)||$d['document_date']<$c->last_event_date)throw new BusinessException('CHECK_INVALID_TRANSITION','حالة الشيك أو تاريخ الحركة لا يسمحان بالعملية.');
            Posting::period($d['document_date']);$entry=null;$changes=['status'=>$target,'last_event_date'=>$d['document_date'],'updated_at'=>now()];
            if($event==='clear'){
                $bank=Posting::treasury('bank',$c->bank_account_id,$c->currency);$fx=app(CurrencySnapshot::class)->execute($c->currency,$d['document_date'],StoreSetting::current()->base_currency);$base=Decimal::mul($c->amount,$fx['exchange_rate']);
                $entry=app(PostSystemJournal::class)->execute('check',$id,'clear',$d['document_date'],$d['reason'],[Posting::line($bank,$base,$c->amount),Posting::line('checks_collection',Decimal::sub('0',$c->base_amount),Decimal::sub('0',$c->amount),$c->exchange_rate),Posting::line('exchange_difference',Decimal::sub($c->base_amount,$base),'0')],$actor,'checks',$fx);$changes['cleared_on']=$d['document_date'];
            }
            if(in_array($event,['bounce','return'])){
                if($event==='return')app(BusinessApproval::class)->require($c->return_approval_id,['check_id'=>$id,'source_payment_id'=>$c->source_payment_id,'amount'=>$c->amount,'currency'=>$c->currency],'checks');
                $allocations=DB::table('payment_allocations')->where('customer_payment_id',$c->source_payment_id)->orderBy('id')->lockForUpdate()->get();$carry='0';$gl=[];
                foreach($allocations as $a){
                    DB::table('payment_allocation_reversals')->insert(['payment_allocation_id'=>$a->id,'sales_invoice_id'=>$a->sales_invoice_id,'check_id'=>$id,'amount'=>$a->amount,'base_amount'=>$a->base_amount,'document_date'=>$d['document_date'],'created_at'=>now()]);$carry=Decimal::add($carry,$a->base_amount);$this->reopenInstallments($a->id);
                }
                $gl[]=Posting::line('ar',$carry,$c->amount);$gl[]=Posting::line($event==='return'?'checks_receivable':'checks_collection',Decimal::sub('0',$c->base_amount),Decimal::sub('0',$c->amount));$gl[]=Posting::line('exchange_difference',Decimal::sub($c->base_amount,$carry),'0');
                $entry=app(PostSystemJournal::class)->execute('check',$id,$event,$d['document_date'],$d['reason'],$gl,$actor,'checks',$this->fx($c));
                app(CustomerLedger::class)->record($c->customer_id,'check',$id,$event,'CHK/'.$c->check_no,$d['document_date'],$c->currency,$c->amount,$carry,$c->exchange_rate,$entry->id,$actor);
                $changes['return_reason']=$d['reason'];if($event==='bounce')$changes['bounced_on']=$d['document_date'];
            }
            DB::table('checks')->where('id',$id)->update($changes);$this->history($id,$c->status,$target,$d['document_date'],$d['reason'],$entry?->id,$actor);
            return DB::table('checks')->find($id);
        },5);
    }
    public function replace(int $id,array $data,int $actor):object {
        return DB::transaction(function()use($id,$data,$actor){
            $old=DB::table('checks')->where('id',$id)->lockForUpdate()->first();abort_unless($old,404);
            if($old->status!=='bounced')throw new BusinessException('CHECK_NOT_BOUNCED','الاستبدال متاح للشيك المرتجع فقط.');
            if($data['customer_id']!==$old->customer_id||$data['currency']!==$old->currency||Decimal::cmp($data['amount'],$old->amount)!==0||$data['received_date']<$old->last_event_date)throw new BusinessException('CHECK_REPLACEMENT_MISMATCH','البديل يجب أن يطابق العميل والمبلغ والعملة وألا يسبق الارتجاع.');
            // The replacement settles the same installment the bounced check was tied to.
            $data['allocations']=DB::table('payment_allocations')->where('customer_payment_id',$old->source_payment_id)->get(['id','sales_invoice_id','amount'])->map(fn($a)=>['sales_invoice_id'=>$a->sales_invoice_id,'amount'=>$a->amount,
                'installment_schedule_id'=>DB::table('installment_allocations as i')->join('installment_schedule as s','s.id','=','i.schedule_id')->where('i.payment_allocation_id',$a->id)->where('s.superseded',false)->orderBy('i.id')->value('s.id')])->all();
            $new=$this->receive($data,$actor);DB::table('checks')->where('id',$id)->update(['status'=>'replaced','replacement_check_id'=>$new->id,'last_event_date'=>$data['received_date'],'updated_at'=>now()]);$this->history($id,'bounced','replaced',$data['received_date'],'استبدال بشيك '.$new->check_no,null,$actor);return $new;
        },5);
    }
    private function reopenInstallments(int $allocation):void {
        foreach(DB::table('installment_allocations')->where('payment_allocation_id',$allocation)->lockForUpdate()->get() as $a){
            $row=DB::table('installment_schedule')->where('id',$a->schedule_id)->lockForUpdate()->first();$c=DB::table('installment_contracts')->where('id',$row->contract_id)->lockForUpdate()->first();
            if($row->superseded)DB::table('installment_schedule')->insert(['contract_id'=>$c->id,'version'=>$c->schedule_version,'sequence_no'=>DB::table('installment_schedule')->where('contract_id',$c->id)->where('version',$c->schedule_version)->max('sequence_no')+1,'due_date'=>$row->due_date,'amount'=>$a->amount,'reopened_from_id'=>$row->id,'created_at'=>now(),'updated_at'=>now()]);
            else DB::table('installment_schedule')->where('id',$row->id)->update(['paid_amount'=>Decimal::sub($row->paid_amount,$a->amount),'updated_at'=>now()]);
            app(AllocateInstallments::class)->refresh($c->id);
        }
    }
    private function fx(object $c):array{return ['currency'=>$c->currency,'exchange_rate'=>$c->exchange_rate,'exchange_rate_date'=>$c->received_date,'exchange_rate_source'=>'original check receipt'];}
    /**
     * Posted receipts that already repaid a bounced check: same customer and currency,
     * dated on or after the bounce, not paid by check, not linked to another check, and
     * allocating at least the check amount to the invoices the check originally paid.
     * Newest first: a repayment is usually the latest receipt, while older ones are
     * often regular installments that merely qualify. The user makes the choice.
     */
    public function settlementCandidates(object $check):array {
        $invoices=DB::table('payment_allocations')->where('customer_payment_id',$check->source_payment_id)->pluck('sales_invoice_id');
        $used=DB::table('checks')->whereNotNull('settlement_payment_id')->pluck('settlement_payment_id');
        $payments=DB::table('customer_payments')->where('customer_id',$check->customer_id)->where('currency',$check->currency)->where('status','posted')->where('method','!=','check')
            ->where('document_date','>=',$check->last_event_date)->where('id','!=',$check->source_payment_id)->whereNotIn('id',$used)->orderByDesc('document_date')->orderByDesc('id')->get(['id','document_no','document_date','method','amount','currency']);
        $rows=[];
        foreach($payments as $p){
            $covered=(string)DB::table('payment_allocations')->where('customer_payment_id',$p->id)->whereIn('sales_invoice_id',$invoices)->sum('amount');
            if(Decimal::cmp($covered,$check->amount)>=0)$rows[]=[...(array)$p,'covered_amount'=>$covered];
        }
        return $rows;
    }
    /** Closes a bounced check against a receipt already posted, so the amount is never collected twice. */
    public function settleWithPayment(int $id,int $paymentId,string $date,string $reason,int $actor):object {
        return DB::transaction(function()use($id,$paymentId,$date,$reason,$actor){
            $c=DB::table('checks')->where('id',$id)->lockForUpdate()->first();abort_unless($c,404);
            if($c->status==='settled'&&(int)$c->settlement_payment_id===$paymentId)return $c;
            if($c->status!=='bounced')throw new BusinessException('CHECK_NOT_BOUNCED','التسوية متاحة للشيكات المرتجعة فقط.');
            // Lock the receipt so two checks cannot claim it concurrently.
            $payment=DB::table('customer_payments')->where('id',$paymentId)->lockForUpdate()->first();abort_unless($payment,404);
            $eligible=collect($this->settlementCandidates($c))->firstWhere('id',$paymentId);
            if(!$eligible)throw new BusinessException('CHECK_SETTLEMENT_PAYMENT_INVALID','السند يجب أن يكون مرحّلاً لنفس العميل والعملة، بتاريخ لا يسبق الارتجاع، وغير مرتبط بشيك آخر، ويغطي قيمة الشيك على فواتيره الأصلية.');
            if($date<$c->last_event_date||$date<$payment->document_date)throw new BusinessException('CHECK_SETTLEMENT_DATE_INVALID','تاريخ التسوية لا يسبق الارتجاع ولا تاريخ السند.');
            DB::table('checks')->where('id',$id)->update(['status'=>'settled','settlement_payment_id'=>$paymentId,'last_event_date'=>$date,'updated_at'=>now()]);
            // No journal: the receipt already credited the customer; this only closes the instrument.
            $this->history($id,'bounced','settled',$date,$reason.' — مرتبط بالسند '.$payment->document_no,null,$actor);
            return DB::table('checks')->find($id);
        },5);
    }
    private function history(int $id,?string $from,string $to,string $date,string $reason,?int $journal,int $actor):void {
        DB::table('check_status_history')->insert(['check_id'=>$id,'from_status'=>$from,'to_status'=>$to,'event_date'=>$date,'reason'=>$reason,'journal_entry_id'=>$journal,'actor_id'=>$actor,'created_at'=>now()]);app(RecordAudit::class)->execute('checks.'.$to,'check',$id,null,['from'=>$from,'to'=>$to,'reason'=>$reason,'journal_entry_id'=>$journal],$actor);
    }
}
