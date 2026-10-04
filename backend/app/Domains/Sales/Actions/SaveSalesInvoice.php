<?php
namespace App\Domains\Sales\Actions;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Customers\Models\Customer;
use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Sales\Models\SalesInvoice;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Domains\Tax\Actions\CalculateDocumentTax;
use App\Domains\Tax\Models\TaxCode;
use App\Models\User;
use App\Support\BusinessException;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class SaveSalesInvoice
{
    public function execute(array $data,int $actor,?int $id=null): SalesInvoice
    {
        return DB::transaction(function()use($data,$actor,$id){
            $store=StoreSetting::sharedCurrent(); $policy=app(BusinessApproval::class)->policy('sales');
            $user=User::findOrFail($actor); $doc=$id?SalesInvoice::lockForUpdate()->findOrFail($id):new SalesInvoice;
            if($id && ($doc->status==='posted' || $doc->version!==(int)$data['version']))throw new BusinessException('INVOICE_NOT_EDITABLE','الفاتورة مرحّلة أو تغيرت نسختها.',409);
            $customer=Customer::lockForUpdate()->findOrFail($data['customer_id']);
            if(!$customer->active || ($data['sale_mode']!=='cash' && $customer->is_walk_in))throw new BusinessException('SALE_CUSTOMER_INVALID','البيع الآجل والتقسيط يتطلبان ملف عميل فعلياً نشطاً.');
            $fx=app(CurrencySnapshot::class)->execute($data['currency'],$data['document_date'],$store->base_currency);
            $totals=array_fill_keys(['net_total','tax_total','foreign_total','base_net_total','base_tax_total','base_total'],'0'); $lines=[]; $warnings=[]; $allSerials=[];
            foreach($data['lines'] as $raw){
                $product=Product::with(['unit','warrantyPolicy'])->sharedLock()->findOrFail($raw['product_id']);
                $location=StockLocation::sharedLock()->findOrFail($raw['location_id']);
                if(!$product->active || !$location->active || !$location->sellable)throw new BusinessException('SALE_STOCK_NOT_SELLABLE','اختر منتجاً نشطاً وموقعاً صالحاً للبيع.');
                $serials=array_map([StockLedger::class,'normalizeSerial'],$raw['serials']);
                app(StockLedger::class)->validateSerials($product,$raw['quantity'],$serials);
                if(array_intersect($allSerials,$serials))throw new BusinessException('SERIAL_DUPLICATE','لا يمكن تكرار جهاز في الفاتورة.');
                $allSerials=[...$allSerials,...$serials];
                $tax=app(CalculateDocumentTax::class)->execute($raw,$data['document_date']);
                $taxCode=TaxCode::sharedLock()->findOrFail($raw['tax_code_id']);
                if(!$store->vat_registered && Decimal::cmp($tax['tax_amount'],'0')>0)throw new BusinessException('OUTPUT_VAT_NOT_CONFIGURED','المتجر غير مسجل للضريبة؛ راجع الإعداد أو رمز الضريبة.');
                $tax['tax_snapshot']=[...$tax['tax_snapshot'],'output_account_id'=>$taxCode->output_account_id,'input_account_id'=>$taxCode->input_account_id];
                $list=$data['sale_mode']==='installment'?$product->installment_price:$product->cash_price;
                $listForeign=(string)Decimal::of($list)->dividedBy($fx['exchange_rate'],4,RoundingMode::HALF_UP);
                $gross=Decimal::mul($raw['unit_price'],$raw['quantity']);
                $effective=Decimal::sub($gross,$raw['discount_amount']);
                if(Decimal::cmp(Decimal::mul($effective,$fx['exchange_rate']),Decimal::mul($product->minimum_price,$raw['quantity']))<0)$warnings[]='بيع أقل من الحد الأدنى: '.$product->name_ar;
                if(Decimal::cmp($raw['unit_price'],$listForeign)!==0 && !$user->hasPermission('sales.override_price'))$warnings[]='تغيير السعر: '.$product->name_ar;
                if(Decimal::cmp($raw['discount_amount'],'0')>0 && (!$user->hasPermission('sales.discount') || Decimal::cmp(Decimal::mul($raw['discount_amount'],'100'),Decimal::mul($gross,$policy['discount_percent']))>0))$warnings[]='خصم يحتاج اعتماداً: '.$product->name_ar;
                $baseNet=Decimal::mul($tax['taxable_base'],$fx['exchange_rate']); $baseTax=Decimal::mul($tax['tax_amount'],$fx['exchange_rate']);
                $line=[...$raw,...$tax,'serials'=>$serials,'list_price'=>$listForeign,'product_snapshot'=>$product->only(['id','sku','name_ar','name_en','serial_tracked','manufacturer_model']),'warranty_snapshot'=>$product->warrantyPolicy?->only(['name_ar','duration_value','duration_unit','provider_type','terms']),'base_net'=>$baseNet,'base_tax'=>$baseTax,'base_total'=>Decimal::add($baseNet,$baseTax),'tax_account_id'=>$taxCode->output_account_id];
                $cashForeign=(string)Decimal::of($product->cash_price)->dividedBy($fx['exchange_rate'],4,RoundingMode::HALF_UP);$cashTax=app(CalculateDocumentTax::class)->execute([...$raw,'unit_price'=>$cashForeign,'discount_amount'=>'0'],$data['document_date']);$line['product_snapshot']['cash_reference_total']=$cashTax['total'];
                $lines[]=$line;
                foreach(['net_total'=>'taxable_base','tax_total'=>'tax_amount','foreign_total'=>'total','base_net_total'=>'base_net','base_tax_total'=>'base_tax','base_total'=>'base_total'] as $total=>$field)$totals[$total]=Decimal::add($totals[$total],$line[$field]);
            }
            if(Decimal::cmp($totals['foreign_total'],'0')<=0)throw new BusinessException('SALE_TOTAL_REQUIRED','إجمالي البيع يجب أن يكون موجباً.');
            $checkout=$data['checkout'];
            if($data['sale_mode']==='cash')$checkout['amount']=$totals['foreign_total'];
            if(Decimal::cmp($checkout['amount']??'0',$totals['foreign_total'])>0)throw new BusinessException('DOWN_PAYMENT_EXCEEDS_TOTAL','الدفعة الأولى تتجاوز قيمة الفاتورة.');
            $before=$doc->exists?$doc->toArray():null;
            $doc->fill([...$fx,...$totals,'customer_id'=>$customer->id,'customer_snapshot'=>$customer->identity(),'document_date'=>$data['document_date'],'due_date'=>$data['due_date'],'sale_mode'=>$data['sale_mode'],'checkout'=>$checkout,'warnings'=>array_values(array_unique($warnings)),'status'=>'draft','version'=>($doc->version??0)+1,'policy_version'=>$policy['version'],'approval_id'=>null,'credit_approval_id'=>null,'notes'=>$data['notes']??null,'delivery_address'=>$data['delivery_address']??null,'delivery_date'=>$data['delivery_date']??null,'created_by'=>$doc->created_by??$actor])->save();
            $doc->lines()->delete(); $doc->lines()->createMany($lines);
            app(RecordAudit::class)->execute($id?'sales.draft_updated':'sales.draft_created','sales_invoice',$doc->id,$before,$doc->fresh('lines')->toArray(),$actor);
            return $doc->fresh('lines');
        },5);
    }
    public function approvalPayload(SalesInvoice $doc): array
    {
        return [...$doc->only(['id','version','customer_id','document_date','currency','exchange_rate','foreign_total','base_total','sale_mode','checkout','warnings','policy_version']),'lines'=>$doc->lines->map(fn($l)=>$l->attributesToArray())->all()];
    }
}
