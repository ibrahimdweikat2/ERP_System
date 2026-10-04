<?php
namespace App\Domains\Sales\Actions;
use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Accounting\Models\Account;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Actions\CustomerLedger;
use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Sales\Models\SalesInvoice;
use App\Domains\Payments\Actions\ReceiveCustomerPayment;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostSalesInvoice
{
    public function execute(int $id,int $actor): SalesInvoice
    {
        return DB::transaction(function()use($id,$actor){
            $store=StoreSetting::sharedCurrent(); $doc=SalesInvoice::with('lines')->lockForUpdate()->findOrFail($id);
            if($doc->status==='posted')return $doc;
            Posting::period($doc->document_date); $customer=Customer::lockForUpdate()->findOrFail($doc->customer_id);
            if(!$customer->active)throw new BusinessException('CUSTOMER_INACTIVE','العميل غير نشط.');
            $policy=app(BusinessApproval::class)->policy('sales');
            if($doc->policy_version!==$policy['version'])throw new BusinessException('SALES_POLICY_CHANGED','تغيرت سياسة البيع؛ راجع المسودة واحفظها مجدداً.');
            if(count($doc->warnings))app(BusinessApproval::class)->require($doc->approval_id,app(SaveSalesInvoice::class)->approvalPayload($doc),'sales');
            if($doc->sale_mode!=='cash')app(\App\Domains\Installments\Actions\CreditDecision::class)->assertSale($doc,$customer,$actor);
            $gl=[]; $cost='0';
            foreach($doc->lines as $line){
                $product=Product::with('unit')->lockForUpdate()->findOrFail($line->product_id); $location=StockLocation::sharedLock()->findOrFail($line->location_id);
                if(!$location->sellable)throw new BusinessException('LOCATION_NOT_SELLABLE','لا يمكن البيع من هذا الموقع.');
                $end=null; $w=$line->warranty_snapshot;
                if($w){$start=CarbonImmutable::parse($doc->document_date);$end=match($w['duration_unit']){'days'=>$start->addDays($w['duration_value']),'months'=>$start->addMonthsNoOverflow($w['duration_value']),'years'=>$start->addYearsNoOverflow($w['duration_value']),default=>$start};$end=$end->toDateString();}
                $movement=app(StockLedger::class)->apply($product,$location,'out',$line->quantity,null,$line->serials,['type'=>'sales_invoice','id'=>$id,'line_id'=>$line->id,'event'=>'sale','date'=>$doc->document_date,'movement_type'=>'sale_issue','customer_id'=>$doc->customer_id,'warranty_end'=>$end],$actor);
                $line->update(['cogs'=>$movement->total_cost,'inventory_movement_id'=>$movement->id]); $cost=Decimal::add($cost,$movement->total_cost);
                $gl[]=Posting::line('sales',Decimal::sub('0',$line->base_net),Decimal::sub('0',$line->taxable_base));
                if(Decimal::cmp($line->base_tax,'0')>0){
                    $account=Account::sharedLock()->find($line->tax_account_id);
                    if(!$store->vat_registered || !$account || !$account->active || !$account->is_control_account || $account->account_type!=='liability')throw new BusinessException('OUTPUT_VAT_ACCOUNT_INVALID','راجع تسجيل المتجر وحساب ضريبة المخرجات.');
                    $gl[]=Posting::line((int)$line->tax_account_id,Decimal::sub('0',$line->base_tax),Decimal::sub('0',$line->tax_amount));
                }
            }
            $gl[]=Posting::line('ar',$doc->base_total,$doc->foreign_total); $gl[]=Posting::line('cogs',$cost,'0'); $gl[]=Posting::line('inventory',Decimal::sub('0',$cost),'0');
            $entry=app(PostSystemJournal::class)->execute('sales_invoice',$id,'sale',$doc->document_date,'فاتورة مبيعات — '.$customer->name,$gl,$actor,'sales',$doc->only(['currency','exchange_rate','exchange_rate_date','exchange_rate_source']));
            $number=app(NextDocumentNumber::class)->execute('sales_invoice',$doc->document_date);
            foreach($doc->lines as $line)DB::table('tax_transactions')->insert(['source_type'=>'sales_invoice','source_id'=>$id,'source_line_id'=>$line->id,'event'=>'sale','direction'=>'output','document_date'=>$doc->document_date,'posting_date'=>$doc->document_date,'tax_code_id'=>$line->tax_code_id,'tax_snapshot'=>json_encode($line->tax_snapshot,JSON_THROW_ON_ERROR),'currency'=>$doc->currency,'exchange_rate'=>$doc->exchange_rate,'tax_rate'=>$line->tax_rate,'tax_inclusive'=>$line->tax_inclusive,'tax_recoverable'=>false,'taxable_base'=>$line->taxable_base,'tax_amount'=>$line->tax_amount,'base_taxable_amount'=>$line->base_net,'base_tax_amount'=>$line->base_tax,'recoverable_base_amount'=>'0','account_id'=>$line->tax_account_id,'journal_entry_id'=>$entry->id,'created_by'=>$actor,'created_at'=>now()]);
            app(CustomerLedger::class)->record($customer->id,'sales_invoice',$id,'sale',$number,$doc->document_date,$doc->currency,$doc->foreign_total,$doc->base_total,$doc->exchange_rate,$entry->id,$actor,$doc->due_date);
            $doc->update(['document_no'=>$number,'status'=>'posted','fulfillment_status'=>'fulfilled','cogs_total'=>$cost,'posted_at'=>now(),'posted_by'=>$actor,'posted_journal_entry_id'=>$entry->id]);
            $down=$doc->sale_mode==='cash'?$doc->foreign_total:($doc->checkout['amount']??'0');
            // Down payment precedes contract creation so it is kept outside the financed schedule.
            if(Decimal::cmp($down,'0')>0){
                $payment=app(ReceiveCustomerPayment::class)->save(['customer_id'=>$customer->id,'currency'=>$doc->currency,'document_date'=>$doc->document_date,'amount'=>$down,'method'=>$doc->checkout['method']??'cash','cashbox_id'=>$doc->checkout['cashbox_id']??null,'bank_account_id'=>$doc->checkout['bank_account_id']??null,'allocations'=>[['sales_invoice_id'=>$id,'amount'=>$down]],'notes'=>'دفعة الفاتورة '.$number],$actor);
                app(ReceiveCustomerPayment::class)->post($payment->id,$actor);
            }
            if($doc->sale_mode==='installment')app(\App\Domains\Installments\Actions\ManageInstallmentContract::class)->fromSale($doc,$actor);
            app(RecordAudit::class)->execute('sales.posted','sales_invoice',$id,null,['document_no'=>$number,'foreign_total'=>$doc->foreign_total,'journal_entry_id'=>$entry->id],$actor);
            return $doc->fresh('lines');
        },5);
    }
}
