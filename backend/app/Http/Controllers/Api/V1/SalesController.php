<?php
namespace App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\DB;
use App\Domains\Sales\Models\SalesInvoice;
use App\Domains\Sales\Models\SalesReturn;
use App\Domains\Sales\Actions\SaveSalesInvoice;
use App\Domains\Sales\Actions\PostSalesInvoice;
use App\Domains\Sales\Actions\ReturnSale;
use App\Domains\Customers\Models\Customer;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Installments\Actions\CreditDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\OperationRules;
use App\Support\BusinessException;
use App\Support\IdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class SalesController extends Controller {
    private function present(SalesInvoice $doc,Request $r):array{
        $data=$doc->toArray();if(!$r->user()->hasPermission('sales.view_cost')){unset($data['cogs_total']);if(isset($data['lines']))foreach($data['lines'] as &$line)unset($line['cogs']);}return $data;
    }
    public function index(Request $r):JsonResponse {
        $d=$r->validate(['search'=>['nullable','string','max:120'],'status'=>['nullable',Rule::in(['draft','posted'])],'sale_mode'=>['nullable',Rule::in(['cash','credit','installment'])],'per_page'=>['nullable','integer','between:1,100']]);
        $p=SalesInvoice::query()->when($d['search']??null,fn($q,$s)=>$q->where(fn($q)=>$q->where('document_no','like',"%$s%")->orWhere('customer_snapshot->name','like',"%$s%")))->when($d['status']??null,fn($q,$s)=>$q->where('status',$s))->when($d['sale_mode']??null,fn($q,$s)=>$q->where('sale_mode',$s))->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25));$p->through(fn($doc)=>$this->present($doc,$r));return response()->json($p);
    }
    public function show(Request $r,SalesInvoice $salesInvoice):JsonResponse{
        $salesInvoice->load('lines');
        // Lines saved before the policy name was snapshotted: show the product's current policy name (read-only; posted lines are immutable).
        $missing=$salesInvoice->lines->filter(fn($l)=>is_array($l->warranty_snapshot)&&empty($l->warranty_snapshot['name_ar']));
        if($missing->isNotEmpty()){$names=DB::table('products')->join('warranty_policies','warranty_policies.id','=','products.warranty_policy_id')->whereIn('products.id',$missing->pluck('product_id'))->pluck('warranty_policies.name_ar','products.id');foreach($missing as $l)if(isset($names[$l->product_id]))$l->warranty_snapshot=[...$l->warranty_snapshot,'name_ar'=>$names[$l->product_id]];}
        return response()->json(['data'=>$this->present($salesInvoice,$r)]);}
    public function save(Request $r,IdempotentRequest $idem,?SalesInvoice $salesInvoice=null):JsonResponse {
        $rules=['version'=>[$salesInvoice?'required':'prohibited','integer','min:1'],'customer_id'=>['required','integer','exists:customers,id'],'document_date'=>OperationRules::date(),'due_date'=>['required','date_format:Y-m-d','after_or_equal:document_date'],'currency'=>['required','string','size:3'],'sale_mode'=>['required',Rule::in(['cash','credit','installment'])],'checkout'=>['required','array:amount,method,cashbox_id,bank_account_id,installment_count,first_due_date,frequency,schedule,terms'],'checkout.amount'=>OperationRules::money(),'checkout.method'=>['required',Rule::in(['cash','bank'])],'checkout.cashbox_id'=>['nullable','integer','exists:cashboxes,id'],'checkout.bank_account_id'=>['nullable','integer','exists:bank_accounts,id'],'checkout.terms'=>['nullable','string','max:5000'],'notes'=>['nullable','string','max:3000'],'delivery_address'=>['nullable','string','max:1000'],'delivery_date'=>['nullable','date_format:Y-m-d'],'lines'=>['required','array','min:1','max:100'],'lines.*'=>['array:product_id,location_id,quantity,unit_price,discount_amount,tax_code_id,tax_inclusive,serials'],'lines.*.product_id'=>['required','integer','exists:products,id'],'lines.*.location_id'=>['required','integer','exists:stock_locations,id'],'lines.*.quantity'=>OperationRules::money(),'lines.*.unit_price'=>OperationRules::money(),'lines.*.discount_amount'=>OperationRules::money(),'lines.*.tax_code_id'=>['required','integer','exists:tax_codes,id'],'lines.*.tax_inclusive'=>['required','boolean'],'lines.*.serials'=>['present','array','max:1000'],'lines.*.serials.*'=>['string','max:120']];
        foreach(OperationRules::schedule() as $k=>$v)$rules['checkout.'.$k]=$v;$d=$r->validate($rules);
        if($salesInvoice?->status==='posted'){app(RecordAudit::class)->execute('sales.edit_rejected','sales_invoice',$salesInvoice->id);throw new BusinessException('POSTED_INVOICE_IMMUTABLE','الفاتورة مرحّلة؛ استخدم مرتجعاً أو إشعاراً دائناً.');}
        $save=fn()=>['id'=>app(SaveSalesInvoice::class)->execute($d,$r->user()->id,$salesInvoice?->id)->id];
        $result=$salesInvoice?$save():$idem->execute($r->user()->id,'sale.create',(string)$r->header('Idempotency-Key'),$d,$save);
        return $this->show($r,SalesInvoice::findOrFail($result['id']));
    }
    public function submit(Request $r,SalesInvoice $salesInvoice):JsonResponse{
        $d=$r->validate(['reason'=>['required','string','min:5','max:1000']]);
        DB::transaction(function()use($r,$salesInvoice,$d){
            $doc=SalesInvoice::with('lines')->lockForUpdate()->findOrFail($salesInvoice->id);if($doc->status==='posted')throw new BusinessException('ALREADY_POSTED','الفاتورة مرحّلة.');
            $changes=[];
            if(count($doc->warnings))$changes['approval_id']=app(BusinessApproval::class)->request('sales_invoice',$doc->id,$doc->version,'sales',app(SaveSalesInvoice::class)->approvalPayload($doc),$d['reason'],$r->user()->id);
            if($doc->sale_mode!=='cash'){$customer=Customer::lockForUpdate()->findOrFail($doc->customer_id);$result=app(CreditDecision::class)->evaluate($doc,$customer);if(count($result['reasons']))$changes['credit_approval_id']=app(BusinessApproval::class)->request('sales_credit',$doc->id,$doc->version,'installments',$result['payload'],$d['reason'],$r->user()->id);}
            if($changes)$doc->update($changes);
        },5);return $this->show($r,$salesInvoice->fresh());
    }
    public function post(Request $r,SalesInvoice $salesInvoice,IdempotentRequest $idem):JsonResponse{
        $result=$idem->execute($r->user()->id,'sale.post.'.$salesInvoice->id,(string)$r->header('Idempotency-Key'),[],fn()=>['id'=>app(PostSalesInvoice::class)->execute($salesInvoice->id,$r->user()->id)->id]);return $this->show($r,SalesInvoice::findOrFail($result['id']));
    }
    public function returns(Request $r,IdempotentRequest $idem):JsonResponse{
        if($r->isMethod('get'))return response()->json(SalesReturn::query()->tap(fn($q)=>\App\Support\Search::apply($q,$r->query('search'),['document_no','customer_snapshot->name','notes']))->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
        $d=$r->validate(['sales_invoice_id'=>['required','integer','exists:sales_invoices,id'],'document_date'=>OperationRules::date(),'reason'=>['required','string','min:5','max:1000'],'lines'=>['required','array','min:1','max:100'],'lines.*'=>['array:sales_invoice_line_id,location_id,quantity,serials,condition'],'lines.*.sales_invoice_line_id'=>['required','integer','distinct','exists:sales_invoice_lines,id'],'lines.*.location_id'=>['required','integer','exists:stock_locations,id'],'lines.*.quantity'=>OperationRules::money(),'lines.*.serials'=>['present','array','max:1000'],'lines.*.serials.*'=>['string','max:120'],'lines.*.condition'=>['required',Rule::in(['unopened','open_box','damaged','warranty'])]]);
        $result=$idem->execute($r->user()->id,'sale_return.create',(string)$r->header('Idempotency-Key'),$d,fn()=>['id'=>app(ReturnSale::class)->save($d,$r->user()->id)->id]);return $this->returnDetail($r,$result['id']);
    }
    public function returnDetail(Request $r,int $id):JsonResponse{
        $data=SalesReturn::with('lines')->findOrFail($id)->toArray();if(!$r->user()->hasPermission('sales.view_cost'))foreach($data['lines'] as &$line)unset($line['cogs']);return response()->json(['data'=>$data]);
    }
    public function postReturn(Request $r,int $id,IdempotentRequest $idem):JsonResponse{$idem->execute($r->user()->id,'sale_return.post.'.$id,(string)$r->header('Idempotency-Key'),[],fn()=>['id'=>app(ReturnSale::class)->post($id,$r->user()->id)->id]);return $this->returnDetail($r,$id);}
}
