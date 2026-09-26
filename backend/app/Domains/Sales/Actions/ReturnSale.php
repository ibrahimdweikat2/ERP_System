<?php
namespace App\Domains\Sales\Actions;
use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Customers\Actions\CustomerLedger;
use App\Domains\Customers\Models\Customer;
use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Sales\Models\SalesInvoice;
use App\Domains\Sales\Models\SalesReturn;
use App\Domains\Installments\Actions\AllocateInstallments;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
class ReturnSale {
    public function save(array $data,int $actor):SalesReturn {
        return DB::transaction(function()use($data,$actor){
            $invoice=SalesInvoice::lockForUpdate()->findOrFail($data['sales_invoice_id']);
            if($invoice->status!=='posted' || $data['document_date']<$invoice->document_date)throw new BusinessException('RETURN_SOURCE_INVALID','اختر فاتورة مرحّلة وتاريخاً لا يسبقها.');
            $doc=SalesReturn::create([...$invoice->only(['customer_id','customer_snapshot','currency','base_currency','exchange_rate','exchange_rate_date','exchange_rate_source']),'sales_invoice_id'=>$invoice->id,'document_date'=>$data['document_date'],'notes'=>$data['reason'],'payload'=>['lines'=>$data['lines']],'created_by'=>$actor]);
            $policy=app(BusinessApproval::class)->policy('sales');
            if(CarbonImmutable::parse($invoice->document_date)->diffInDays($data['document_date'])>$policy['return_window_days']){
                $approval=app(BusinessApproval::class)->request('sales_return',$doc->id,1,'sales',$this->payload($doc),$data['reason'],$actor);$doc->update(['approval_id'=>$approval]);
            }
            app(RecordAudit::class)->execute('sales.return_created','sales_return',$doc->id,null,$doc->toArray(),$actor);return $doc;
        },5);
    }
    private function payload(SalesReturn $d):array{return $d->only(['id','sales_invoice_id','document_date','payload','notes']);}
    public function post(int $id,int $actor):SalesReturn {
        return DB::transaction(function()use($id,$actor){
            $doc=SalesReturn::lockForUpdate()->findOrFail($id);if($doc->status==='posted')return $doc->load('lines');
            Posting::period($doc->document_date);Customer::lockForUpdate()->findOrFail($doc->customer_id);
            $invoice=SalesInvoice::with('lines')->lockForUpdate()->findOrFail($doc->sales_invoice_id);
            $p=app(BusinessApproval::class)->policy('sales');
            if(CarbonImmutable::parse($invoice->document_date)->diffInDays($doc->document_date)>$p['return_window_days'])app(BusinessApproval::class)->require($doc->approval_id,$this->payload($doc),'sales');
            $total='0';$base='0';$gl=[];$taxRows=[];
            foreach($doc->payload['lines'] as $raw){
                $original=$invoice->lines->firstWhere('id',(int)$raw['sales_invoice_line_id']);if(!$original)throw new BusinessException('RETURN_LINE_INVALID','السطر لا ينتمي للفاتورة الأصلية.');
                $location=StockLocation::sharedLock()->findOrFail($raw['location_id']);
                if(!$location->active || $location->sellable || !in_array($location->purpose,['returns','damaged','warranty']))throw new BusinessException('RETURN_INSPECTION_REQUIRED','أدخل المرتجع إلى موقع فحص أو تالف غير قابل للبيع.');
                $product=Product::with('unit')->lockForUpdate()->findOrFail($original->product_id);$serials=array_map([StockLedger::class,'normalizeSerial'],$raw['serials']);
                foreach($serials as $s)if(!in_array($s,$original->serials,true))throw new BusinessException('RETURN_SERIAL_INVALID','الجهاز ليس من الأجهزة المباعة بهذه الفاتورة.');
                $prior=DB::table('sales_return_lines')->where('sales_invoice_line_id',$original->id)->lockForUpdate()->get();$qty='0';$allocated=array_fill_keys(['net','tax','base_net','base_tax','cogs'],'0');
                foreach($prior as $row){$qty=Decimal::add($qty,$row->quantity);foreach($allocated as $f=>$v)$allocated[$f]=Decimal::add($allocated[$f],$row->$f);}
                $values=[];foreach(['net'=>'taxable_base','tax'=>'tax_amount','base_net'=>'base_net','base_tax'=>'base_tax','cogs'=>'cogs'] as $target=>$source)$values[$target]=Posting::portion($original->$source,$original->quantity,$qty,$raw['quantity'],$allocated[$target]);
                $values['amount']=Decimal::add($values['net'],$values['tax']);$values['base_amount']=Decimal::add($values['base_net'],$values['base_tax']);
                $line=$doc->lines()->create([...$values,'sales_invoice_line_id'=>$original->id,'location_id'=>$location->id,'quantity'=>$raw['quantity'],'serials'=>$serials,'product_snapshot'=>$original->product_snapshot,'condition'=>$raw['condition']]);
                $move=app(StockLedger::class)->apply($product,$location,'in',$raw['quantity'],$values['cogs'],$serials,['type'=>'sales_return','id'=>$id,'line_id'=>$line->id,'event'=>'return','date'=>$doc->document_date,'movement_type'=>'sales_return','original_line_id'=>$original->id],$actor);
                $line->update(['inventory_movement_id'=>$move->id]);
                $gl[]=Posting::line('sales_returns',$values['base_net'],$values['net']);$gl[]=Posting::line('ar',Decimal::sub('0',$values['base_amount']),Decimal::sub('0',$values['amount']));
                $gl[]=Posting::line('inventory',$values['cogs'],'0');$gl[]=Posting::line('cogs',Decimal::sub('0',$values['cogs']),'0');
                if(Decimal::cmp($values['base_tax'],'0')>0)$gl[]=Posting::line((int)$original->tax_account_id,$values['base_tax'],$values['tax']);
                $total=Decimal::add($total,$values['amount']);$base=Decimal::add($base,$values['base_amount']);
                $taxRows[]=['source_type'=>'sales_return','source_id'=>$id,'source_line_id'=>$line->id,'event'=>'return','direction'=>'output','document_date'=>$doc->document_date,'posting_date'=>$doc->document_date,'tax_code_id'=>$original->tax_code_id,'tax_snapshot'=>json_encode($original->tax_snapshot,JSON_THROW_ON_ERROR),'currency'=>$doc->currency,'exchange_rate'=>$doc->exchange_rate,'tax_rate'=>$original->tax_rate,'tax_inclusive'=>$original->tax_inclusive,'tax_recoverable'=>false,'taxable_base'=>Decimal::sub('0',$values['net']),'tax_amount'=>Decimal::sub('0',$values['tax']),'base_taxable_amount'=>Decimal::sub('0',$values['base_net']),'base_tax_amount'=>Decimal::sub('0',$values['base_tax']),'recoverable_base_amount'=>'0','account_id'=>$original->tax_account_id,'created_by'=>$actor,'created_at'=>now()];
            }
            $concessionTotal='0';$concessionBase='0';
            $priorReturns=(string)DB::table('sales_returns')->where('sales_invoice_id',$invoice->id)->where('status','posted')->sum('amount');
            foreach(DB::table('customer_settlements')->where('sales_invoice_id',$invoice->id)->where('kind','early_settlement')->where('status','posted')->lockForUpdate()->get() as $settlement){
                $sp=json_decode($settlement->payload,true,512,JSON_THROW_ON_ERROR);$baseline=$sp['return_total']??'0';$whole=Decimal::sub($invoice->foreign_total,$baseline);$already=Decimal::sub($priorReturns,$baseline);$used=DB::table('sales_return_concessions')->where('settlement_id',$settlement->id)->get();$allocated='0';$allocatedBase='0';foreach($used as $u){$allocated=Decimal::add($allocated,$u->amount);$allocatedBase=Decimal::add($allocatedBase,$u->base_amount);}
                $part=Posting::portion($settlement->amount,$whole,$already,$total,$allocated);$partBase=Posting::portion($settlement->base_amount,$whole,$already,$total,$allocatedBase);
                DB::table('sales_return_concessions')->insert(['sales_return_id'=>$id,'settlement_id'=>$settlement->id,'sales_invoice_id'=>$invoice->id,'amount'=>$part,'base_amount'=>$partBase,'document_date'=>$doc->document_date,'created_at'=>now()]);
                $gl[]=Posting::line('ar',$partBase,$part);$gl[]=Posting::line((int)$sp['account_id'],Decimal::sub('0',$partBase),Decimal::sub('0',$part));$concessionTotal=Decimal::add($concessionTotal,$part);$concessionBase=Decimal::add($concessionBase,$partBase);
            }
            $entry=collect($gl)->contains(fn($l)=>Decimal::cmp($l['debit'],'0')>0||Decimal::cmp($l['credit'],'0')>0)?app(PostSystemJournal::class)->execute('sales_return',$id,'return',$doc->document_date,$doc->notes,$gl,$actor,'sales',$doc->only(['currency','exchange_rate','exchange_rate_date','exchange_rate_source'])) : null;
            foreach($taxRows as $row)DB::table('tax_transactions')->insert([...$row,'journal_entry_id'=>$entry?->id]);
            $number=app(NextDocumentNumber::class)->execute('sales_credit_note',$doc->document_date);
            app(CustomerLedger::class)->record($doc->customer_id,'sales_return',$id,'return',$number,$doc->document_date,$doc->currency,Decimal::sub('0',$total),Decimal::sub('0',$base),$doc->exchange_rate,$entry?->id,$actor);
            if(Decimal::cmp($concessionTotal,'0')>0)app(CustomerLedger::class)->record($doc->customer_id,'sales_return',$id,'discount_reversal',$number,$doc->document_date,$doc->currency,$concessionTotal,$concessionBase,$doc->exchange_rate,$entry?->id,$actor);
            $doc->update(['status'=>'posted','document_no'=>$number,'amount'=>$total,'base_amount'=>$base,'posted_at'=>now(),'posted_by'=>$actor,'posted_journal_entry_id'=>$entry?->id]);
            app(AllocateInstallments::class)->credit($doc->sales_invoice_id,Decimal::sub($total,$concessionTotal),'sales_return',$id);
            app(RecordAudit::class)->execute('sales.return_posted','sales_return',$id,null,['document_no'=>$number,'amount'=>$total,'journal_entry_id'=>$entry?->id],$actor);
            return $doc->fresh('lines');
        },5);
    }
}
