<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Tax\Models\TaxCode;
use App\Domains\CashBank\Models\Cashbox;
use App\Domains\CashBank\Models\BankAccount;
use App\Domains\Accounting\Models\Account;
use App\Domains\StoreSetup\Models\Currency;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\IdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class OperationsController extends Controller {
    public function options(Request $r):JsonResponse{
        $u=$r->user();$data=['currencies'=>Currency::where('is_active',true)->get(['code','name']),'cashboxes'=>[],'banks'=>[],'locations'=>[],'taxes'=>[],'accounts'=>[]];
        if(collect(['sales.create','payments.receive','purchasing.pay','checks.deposit','expenses.create','cashbank.manage','cashbank.view','installments.early_settlement','sales.cancel'])->contains(fn($p)=>$u->hasPermission($p))){$data['cashboxes']=Cashbox::where('active',true)->get(['id','name','currency_code']);$data['banks']=BankAccount::where('active',true)->get(['id','name','bank_name','currency_code']);}
        if(collect(['sales.create','purchasing.invoice','inventory.view'])->contains(fn($p)=>$u->hasPermission($p)))$data['locations']=StockLocation::where('active',true)->get();
        if(collect(['sales.create','purchasing.invoice','expenses.create'])->contains(fn($p)=>$u->hasPermission($p)))$data['taxes']=TaxCode::orderByDesc('effective_from')->get();
        if($u->hasPermission('expenses.create')||$u->hasPermission('installments.early_settlement'))$data['accounts']=Account::where('active',true)->where('account_type','expense')->where('is_control_account',false)->get(['id','code','name_ar']);
        return response()->json(['data'=>$data]);
    }
    public function products(Request $r):JsonResponse{
        $d=$r->validate(['search'=>['nullable','string','max:120'],'location_id'=>['nullable','integer'],'per_page'=>['nullable','integer','between:1,100']]);
        $p=Product::with(['barcodes','brand:id,name_ar','category:id,name_ar'])->where('active',true)->when($d['search']??null,fn($q,$s)=>$q->where(fn($q)=>$q->where('name_ar','like',"%$s%")->orWhere('name_en','like',"%$s%")->orWhere('sku','like',"%$s%")->orWhere('manufacturer_model','like',"%$s%")->orWhereHas('barcodes',fn($q)=>$q->where('barcode',$s))->orWhereHas('brand',fn($q)=>$q->where('name_ar','like',"%$s%"))))->orderBy('name_ar')->paginate(\App\Support\PerPage::resolve(25));
        $stocks=DB::table('inventory_balances')->whereIn('product_id',$p->getCollection()->pluck('id'))->when($d['location_id']??null,fn($q,$v)=>$q->where('location_id',$v))->get(['product_id','location_id','qty_available']);
        $p->through(fn($product)=>[...$product->only(['id','sku','name_ar','name_en','manufacturer_model','cash_price','installment_price','minimum_price','tax_code_id','serial_tracked']),'brand'=>$product->brand,'barcodes'=>$product->barcodes,'stock'=>$stocks->where('product_id',$product->id)->values()]);return response()->json($p);
    }
    public function policies():JsonResponse{return response()->json(['data'=>DB::table('business_policies')->get()->map(fn($p)=>['key'=>$p->key,'version'=>$p->version,'settings'=>json_decode($p->settings,true,512,JSON_THROW_ON_ERROR)])]);}
    public function policy(Request $r,string $key):JsonResponse{
        $rules=match($key){'sales'=>['discount_percent'=>['required','string',Decimal::MONEY_RULE,'numeric','max:100'],'return_window_days'=>['required','integer','between:0,3650']],'installments'=>['minimum_down_payment_percent'=>['required','string',Decimal::MONEY_RULE,'numeric','max:100'],'markup_recognition'=>['required',Rule::in(['unconfigured','full_price_at_sale'])],'allow_credit_override'=>['required','boolean'],'grace_days'=>['required','integer','between:0,365']],'treasury'=>['enforce_sessions'=>['required','boolean'],'expense_approval_threshold'=>['required','string',Decimal::MONEY_RULE],'variance_approval_threshold'=>['required','string',Decimal::MONEY_RULE]],'checks'=>['ar_recognition'=>['required',Rule::in(['on_receipt'])],'allow_early_deposit'=>['required','boolean']],default=>abort(404)};
        $rules+=['segregate_requester'=>['required','boolean']];$validation=['version'=>['required','integer','min:1'],'reason'=>['required','string','min:5','max:1000'],'settings'=>['required','array:'.implode(',',array_keys($rules))]];foreach($rules as $k=>$v)$validation['settings.'.$k]=$v;$d=$r->validate($validation);
        DB::transaction(function()use($key,$d,$r){$p=DB::table('business_policies')->where('key',$key)->lockForUpdate()->first();if($p->version!==(int)$d['version'])throw new BusinessException('POLICY_VERSION_CONFLICT','تغيرت السياسة؛ أعد تحميلها.',409);DB::table('business_policies')->where('key',$key)->update(['settings'=>json_encode($d['settings'],JSON_THROW_ON_ERROR),'version'=>$p->version+1,'updated_at'=>now()]);app(RecordAudit::class)->execute('operations.policy_updated','business_policy',null,['key'=>$key,'settings'=>json_decode($p->settings,true)],['key'=>$key,...$d],$r->user()->id);},5);return $this->policies();
    }
    public function approvals(Request $r):JsonResponse{
        $keys=[];foreach(['sales'=>'sales.override_price','installments'=>'installments.approve','treasury'=>'expenses.approve','checks'=>'checks.return_to_customer'] as $key=>$p)if($r->user()->hasPermission($p))$keys[]=$key;
        return response()->json(DB::table('workflow_approvals')->where(fn($q)=>$q->whereIn('policy_key',$keys)->orWhere('requested_by',$r->user()->id))->tap(fn($q)=>\App\Support\Search::apply($q,$r->query('search'),['reason','source_type','policy_key','status']))->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
    }
    public function decide(Request $r,int $id,IdempotentRequest $idem):JsonResponse{$d=$r->validate(['decision'=>['required',Rule::in(['approved','rejected'])],'reason'=>['required','string','min:5','max:1000']]);$result=$idem->execute($r->user()->id,'workflow.decide.'.$id,(string)$r->header('Idempotency-Key'),$d,fn()=>['id'=>app(BusinessApproval::class)->decide($id,$d['decision'],$d['reason'],$r->user()->id)->id]);return response()->json(['data'=>DB::table('workflow_approvals')->find($result['id'])]);}
}
