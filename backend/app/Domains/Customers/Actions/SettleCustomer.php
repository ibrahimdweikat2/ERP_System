<?php
namespace App\Domains\Customers\Actions;
use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Accounting\Models\Account;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\SalesInvoice;
use App\Domains\Payments\Actions\ReceiveCustomerPayment;
use App\Domains\Installments\Actions\AllocateInstallments;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;
class SettleCustomer {
    public function save(array $d,int $actor):object{return DB::transaction(function()use($d,$actor){
        $sale=SalesInvoice::lockForUpdate()->findOrFail($d['sales_invoice_id']);Customer::lockForUpdate()->findOrFail($sale->customer_id);
        if($sale->status!=='posted'||$d['document_date']<$sale->document_date||Decimal::cmp($d['amount'],'0')<=0)throw new BusinessException('SETTLEMENT_INVALID','اختر فاتورة مرحّلة ومبلغاً موجباً وتاريخاً لا يسبقها.');
        $balance=app(CustomerLedger::class)->invoice($sale);
        if($d['kind']==='early_settlement'&&$sale->sale_mode!=='installment')throw new BusinessException('CONTRACT_REQUIRED','التسوية المبكرة مخصصة لفواتير التقسيط.');
        $payload=[...$d,'balance'=>$balance,'return_total'=>(string)DB::table('sales_returns')->where('sales_invoice_id',$sale->id)->where('status','posted')->sum('amount')];$id=DB::table('customer_settlements')->insertGetId(['kind'=>$d['kind'],'customer_id'=>$sale->customer_id,'sales_invoice_id'=>$sale->id,'target_invoice_id'=>$d['target_invoice_id']??null,'document_date'=>$d['document_date'],'currency'=>$sale->currency,'amount'=>$d['amount'],'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'reason'=>$d['reason'],'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        if($d['kind']==='early_settlement'){$approval=app(BusinessApproval::class)->request('customer_credit',$id,1,'installments',$payload,$d['reason'],$actor);DB::table('customer_settlements')->where('id',$id)->update(['approval_id'=>$approval]);}
        app(RecordAudit::class)->execute('customers.settlement_created','customer_settlement',$id,null,$d,$actor);return DB::table('customer_settlements')->find($id);
    },5);}
    public function post(int $id,int $actor):object{return DB::transaction(function()use($id,$actor){
        $doc=DB::table('customer_settlements')->where('id',$id)->lockForUpdate()->first();abort_unless($doc,404);if($doc->status==='posted')return $doc;
        Posting::period($doc->document_date);Customer::lockForUpdate()->findOrFail($doc->customer_id);$sale=SalesInvoice::lockForUpdate()->findOrFail($doc->sales_invoice_id);$b=app(CustomerLedger::class)->invoice($sale);$d=json_decode($doc->payload,true,512,JSON_THROW_ON_ERROR);
        $last=DB::table('customer_ledger_entries')->where('customer_id',$doc->customer_id)->where('currency',$doc->currency)->max('posting_date');if($last&&$doc->document_date<$last)throw new BusinessException('SETTLEMENT_BACKDATED','تاريخ التسوية لا يسبق آخر حركة للعميل بهذه العملة.');
        $fx=app(CurrencySnapshot::class)->execute($doc->currency,$doc->document_date,StoreSetting::current()->base_currency);$gl=[];$allocations=[];$paymentId=null;
        if($doc->kind==='early_settlement'){
            if($b!==$d['balance'])throw new BusinessException('SETTLEMENT_BALANCE_CHANGED','تغير الرصيد منذ طلب الخصم؛ أنشئ طلباً محدثاً.');
            app(BusinessApproval::class)->require($doc->approval_id,$d,'installments');if(Decimal::cmp($doc->amount,$b['remaining'])>=0)throw new BusinessException('DISCOUNT_TOO_LARGE','الخصم يجب أن يقل عن الرصيد المتبقي.');
            $account=Account::sharedLock()->findOrFail($d['account_id']);if(!$account->active||$account->is_control_account||$account->account_type!=='expense')throw new BusinessException('DISCOUNT_ACCOUNT_INVALID','اختر حساب مصروف فعّال لخصم التحصيل.');
            $base=Posting::portion($b['remaining_base'],$b['remaining'],'0',$doc->amount,'0');$gl=[Posting::line($account->id,$base,$doc->amount),Posting::line('ar',Decimal::sub('0',$base),Decimal::sub('0',$doc->amount))];$allocations[]=['invoice'=>$sale->id,'amount'=>Decimal::sub('0',$doc->amount),'base'=>Decimal::sub('0',$base)];
        }else{
            $available=Decimal::sub('0',$b['remaining']);$availableBase=Decimal::sub('0',$b['remaining_base']);if(Decimal::cmp($available,'0')<=0||Decimal::cmp($doc->amount,$available)>0)throw new BusinessException('CUSTOMER_CREDIT_REQUIRED','المبلغ يتجاوز الرصيد الدائن المتاح على الفاتورة.');
            $base=Posting::portion($availableBase,$available,'0',$doc->amount,'0');$gl[] = Posting::line('ar',$base,$doc->amount);$allocations[]=['invoice'=>$sale->id,'amount'=>$doc->amount,'base'=>$base];
            if($doc->kind==='refund'){$treasury=Posting::treasury($d['method'],(int)($d[$d['method']==='cash'?'cashbox_id':'bank_account_id']??0),$doc->currency);$cashBase=Decimal::mul($doc->amount,$fx['exchange_rate']);$gl[]=Posting::line($treasury,Decimal::sub('0',$cashBase),Decimal::sub('0',$doc->amount));$gl[]=Posting::line('exchange_difference',Decimal::sub($cashBase,$base),'0');}
            else{$target=SalesInvoice::lockForUpdate()->findOrFail($doc->target_invoice_id);if($target->id===$sale->id||$target->customer_id!==$sale->customer_id||$target->currency!==$sale->currency||$target->status!=='posted')throw new BusinessException('CREDIT_TARGET_INVALID','اختر فاتورة أخرى مرحّلة للعميل وبالعملة نفسها.');$targetBalance=app(CustomerLedger::class)->invoice($target);$targetBase=Posting::portion($targetBalance['remaining_base'],$targetBalance['remaining'],'0',$doc->amount,'0');$gl[]=Posting::line('ar',Decimal::sub('0',$targetBase),Decimal::sub('0',$doc->amount));$gl[]=Posting::line('exchange_difference',Decimal::sub($targetBase,$base),'0');$allocations[]=['invoice'=>$target->id,'amount'=>Decimal::sub('0',$doc->amount),'base'=>Decimal::sub('0',$targetBase)];}
        }
        $entry=app(PostSystemJournal::class)->execute('customer_settlement',$id,'settlement',$doc->document_date,$doc->reason,$gl,$actor,'general',$fx);$number=app(NextDocumentNumber::class)->execute($doc->kind==='refund'?'payment_voucher':'sales_credit_note',$doc->document_date);
        foreach($allocations as $a){DB::table('customer_settlement_allocations')->insert(['settlement_id'=>$id,'sales_invoice_id'=>$a['invoice'],'amount'=>$a['amount'],'base_amount'=>$a['base'],'document_date'=>$doc->document_date,'created_at'=>now()]);app(CustomerLedger::class)->record($doc->customer_id,'customer_settlement',$id,'invoice_'.$a['invoice'],$number,$doc->document_date,$doc->currency,$a['amount'],$a['base'],$fx['exchange_rate'],$entry->id,$actor);if(Decimal::cmp($a['amount'],'0')<0)app(AllocateInstallments::class)->credit($a['invoice'],Decimal::sub('0',$a['amount']),'customer_settlement',$id);}
        if($doc->kind==='early_settlement'){$payment=app(ReceiveCustomerPayment::class)->save(['customer_id'=>$doc->customer_id,'document_date'=>$doc->document_date,'currency'=>$doc->currency,'amount'=>Decimal::sub($b['remaining'],$doc->amount),'method'=>$d['method'],'cashbox_id'=>$d['cashbox_id']??null,'bank_account_id'=>$d['bank_account_id']??null,'allocations'=>[['sales_invoice_id'=>$sale->id,'amount'=>Decimal::sub($b['remaining'],$doc->amount)]],'notes'=>$doc->reason],$actor);app(ReceiveCustomerPayment::class)->post($payment->id,$actor);$paymentId=$payment->id;}
        DB::table('customer_settlements')->where('id',$id)->update(['status'=>'posted','document_no'=>$number,'base_amount'=>$base,'payment_id'=>$paymentId,'posted_journal_entry_id'=>$entry->id,'posted_by'=>$actor,'posted_at'=>now(),'updated_at'=>now()]);app(RecordAudit::class)->execute('customers.settlement_posted','customer_settlement',$id,null,['document_no'=>$number,'journal_entry_id'=>$entry->id],$actor);return DB::table('customer_settlements')->find($id);
    },5);}
}
