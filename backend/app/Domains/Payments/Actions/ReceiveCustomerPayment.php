<?php
namespace App\Domains\Payments\Actions;
use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Actions\CustomerLedger;
use App\Domains\Payments\Models\CustomerPayment;
use App\Domains\Sales\Models\SalesInvoice;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;

class ReceiveCustomerPayment
{
    public function save(array $data,int $actor): CustomerPayment
    {
        return DB::transaction(function()use($data,$actor){
            $store=StoreSetting::sharedCurrent(); $customer=Customer::lockForUpdate()->findOrFail($data['customer_id']);
            if(!$customer->active || Decimal::cmp($data['amount'],'0')<=0)throw new BusinessException('PAYMENT_INVALID','اختر عميلاً نشطاً ومبلغاً موجباً.');
            $fx=app(CurrencySnapshot::class)->execute($data['currency'],$data['document_date'],$store->base_currency);
            $doc=CustomerPayment::create([...$fx,'customer_id'=>$customer->id,'customer_snapshot'=>$customer->identity(),'document_date'=>$data['document_date'],'method'=>$data['method'],'amount'=>$data['amount'],'base_amount'=>Decimal::mul($data['amount'],$fx['exchange_rate']),'cashbox_id'=>$data['method']==='cash'?$data['cashbox_id']:null,'bank_account_id'=>$data['method']==='bank'?$data['bank_account_id']:null,'payment_reference'=>$data['payment_reference']??null,'notes'=>$data['notes']??null,'allocation_request'=>$data['allocations']??[],'created_by'=>$actor]);
            app(RecordAudit::class)->execute('payments.created','customer_payment',$doc->id,null,$doc->toArray(),$actor);
            return $doc;
        },5);
    }
    /** Check receipt calls this inside the instrument transaction with an internal control-account override. */
    public function post(int $id,int $actor,?int $checkAccount=null): CustomerPayment
    {
        return DB::transaction(function()use($id,$actor,$checkAccount){
            $doc=CustomerPayment::lockForUpdate()->findOrFail($id);
            if($doc->status==='posted')return $doc->load('allocations');
            Posting::period($doc->document_date); Customer::lockForUpdate()->findOrFail($doc->customer_id);
            if($doc->method==='check' && !$checkAccount)throw new BusinessException('CHECK_RECEIPT_REQUIRED','استلام الشيك يتم من مركز الشيكات.');
            $cashAccount=$checkAccount??Posting::treasury($doc->method,$doc->method==='cash'?$doc->cashbox_id:$doc->bank_account_id,$doc->currency);
            $requests=collect($doc->allocation_request)->keyBy('sales_invoice_id');
            $invoices=SalesInvoice::where('customer_id',$doc->customer_id)->where('currency',$doc->currency)->where('status','posted')->when($requests->isNotEmpty(),fn($q)=>$q->whereIn('id',$requests->keys()))->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
            if($requests->isNotEmpty() && $invoices->count()!==$requests->count())throw new BusinessException('PAYMENT_INVOICE_MISMATCH','اختر فواتير مرحّلة لنفس العميل والعملة.');
            $left=$doc->amount; $carry='0'; $gl=[];
            foreach($invoices as $invoice){
                $b=app(CustomerLedger::class)->invoice($invoice);
                $amount=$requests->isNotEmpty()?$requests[$invoice->id]['amount']:(Decimal::cmp($left,$b['remaining'])<0?$left:$b['remaining']);
                if(Decimal::cmp($amount,'0')<=0)continue;
                if($invoice->document_date>$doc->document_date || Decimal::cmp($amount,$left)>0 || Decimal::cmp($amount,$b['remaining'])>0)throw new BusinessException('PAYMENT_ALLOCATION_INVALID','مبلغ التوزيع يتجاوز رصيد الفاتورة أو يسبق تاريخها.');
                $base=Posting::portion($invoice->base_total,$invoice->foreign_total,$b['settled'],$amount,$b['settled_base']);
                $allocation=$doc->allocations()->create(['sales_invoice_id'=>$invoice->id,'amount'=>$amount,'base_amount'=>$base]);
                $gl[]=Posting::line('ar',Decimal::sub('0',$base),Decimal::sub('0',$amount),$invoice->exchange_rate);
                $carry=Decimal::add($carry,$base); $left=Decimal::sub($left,$amount);
                $target=$requests->isNotEmpty()?($requests[$invoice->id]['installment_schedule_id']??null):null;
                app(\App\Domains\Installments\Actions\AllocateInstallments::class)->payment($allocation->id,$invoice->id,$amount,$doc->document_date,$target!==null?(int)$target:null);
            }
            if(Decimal::cmp($left,'0')!==0)throw new BusinessException('PAYMENT_UNALLOCATED','وزّع المبلغ بالكامل على الفواتير المفتوحة.');
            $gl[]=Posting::line($cashAccount,$doc->base_amount,$doc->amount);
            $gl[]=Posting::line('exchange_difference',Decimal::sub($carry,$doc->base_amount),'0');
            $entry=app(PostSystemJournal::class)->execute('customer_payment',$id,'receipt',$doc->document_date,'قبض من العميل '.$doc->customer_snapshot['name'],$gl,$actor,'receipts',$doc->only(['currency','exchange_rate','exchange_rate_date','exchange_rate_source']));
            $number=app(NextDocumentNumber::class)->execute('customer_receipt',$doc->document_date);
            app(CustomerLedger::class)->record($doc->customer_id,'customer_payment',$id,'receipt',$number,$doc->document_date,$doc->currency,Decimal::sub('0',$doc->amount),Decimal::sub('0',$carry),$doc->exchange_rate,$entry->id,$actor);
            $doc->update(['status'=>'posted','document_no'=>$number,'posted_at'=>now(),'posted_by'=>$actor,'posted_journal_entry_id'=>$entry->id]);
            app(RecordAudit::class)->execute('payments.posted','customer_payment',$id,null,['document_no'=>$number,'amount'=>$doc->amount,'journal_entry_id'=>$entry->id],$actor);
            return $doc->fresh('allocations');
        },5);
    }
}
