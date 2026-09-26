<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Actions\CustomerLedger;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Sales\Models\SalesInvoice;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\IdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $d=$r->validate(['search'=>['nullable','string','max:120'],'per_page'=>['nullable','integer','between:1,100']]);
        return response()->json(Customer::when($d['search']??null,fn($q,$s)=>$q->where(fn($q)=>$q->where('name','like',"%$s%")->orWhere('phone','like',"%$s%")->orWhere('code','like',"%$s%")))->orderBy('name')->paginate(\App\Support\PerPage::resolve(25)));
    }
    public function show(Customer $customer): JsonResponse { return response()->json(['data'=>$customer]); }
    public function save(Request $r,IdempotentRequest $idem,?Customer $customer=null): JsonResponse
    {
        $data=$r->validate(['code'=>['required','string','max:50',Rule::unique('customers')->ignore($customer?->id)],'name'=>['required','string','max:200'],'identity_number'=>['sometimes','nullable','string','max:100'],'tax_number'=>['nullable','string','max:60'],'phone'=>['nullable','string','max:60'],'email'=>['nullable','email','max:200'],'address'=>['nullable','string','max:1000'],'workplace'=>['nullable','string','max:200'],'currency'=>['required','string','exists:currencies,code'],'credit_limit'=>['required','string',Decimal::MONEY_RULE],'max_active_contracts'=>['required','integer','between:0,1000'],'max_overdue_days'=>['required','integer','between:0,3650'],'risk_flag'=>['required',Rule::in(['normal','watch','blocked'])],'is_walk_in'=>['required','boolean'],'active'=>['required','boolean'],'notes'=>['nullable','string','max:3000'],'version'=>[$customer?'required':'prohibited','integer','min:1']]);
        $save=function()use($data,$customer,$r){return DB::transaction(function()use($data,$customer,$r){
            $doc=$customer?Customer::lockForUpdate()->findOrFail($customer->id):new Customer;
            if($customer && $doc->version!==(int)$data['version'])throw new BusinessException('VERSION_CONFLICT','تغير ملف العميل. أعد تحميله.',409);
            if($customer && $doc->is_walk_in!==$data['is_walk_in'] && SalesInvoice::where('customer_id',$doc->id)->exists())throw new BusinessException('CUSTOMER_TYPE_LOCKED','نوع العميل محفوظ بعد استخدامه في المبيعات.');
            if(!$r->user()->hasPermission('installments.approve')&&!$r->user()->hasPermission('settings.manage')){foreach(['credit_limit'=>'0','max_active_contracts'=>1,'max_overdue_days'=>0,'risk_flag'=>'normal'] as $field=>$default){$data[$field]=$doc->exists?$doc->$field:$default;}}
            $before=$doc->exists?$doc->toArray():null;
            $doc->fill([...$data,'version'=>($doc->version??0)+1,'created_by'=>$doc->created_by??$r->user()->id])->save();
            app(RecordAudit::class)->execute($customer?'customers.updated':'customers.created','customer',$doc->id,$before,$doc->toArray(),$r->user()->id);
            return ['id'=>$doc->id];
        },5);};
        $result=$customer?$save():$idem->execute($r->user()->id,'customer.create',(string)$r->header('Idempotency-Key'),$data,$save);
        return response()->json(['data'=>Customer::findOrFail($result['id'])],$customer?200:201);
    }
    public function statement(Request $r,Customer $customer): JsonResponse
    {
        $d=$r->validate(['currency'=>['nullable','string','size:3'],'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from']]);
        $base=DB::table('customer_ledger_entries')->where('customer_id',$customer->id)->when($d['currency']??null,fn($q,$v)=>$q->where('currency',$v));
        $window=(clone $base)->selectRaw('*, SUM(foreign_amount) OVER (PARTITION BY currency ORDER BY posting_date,id ROWS UNBOUNDED PRECEDING) AS running_balance, SUM(base_amount) OVER (PARTITION BY currency ORDER BY posting_date,id ROWS UNBOUNDED PRECEDING) AS running_base_balance');
        $rows=DB::query()->fromSub($window,'ledger')->when($d['from']??null,fn($q,$v)=>$q->where('posting_date','>=',$v))->when($d['to']??null,fn($q,$v)=>$q->where('posting_date','<=',$v))->orderBy('posting_date')->orderBy('id')->paginate(\App\Support\PerPage::resolve(50));
        $totals=(clone $base)->when($d['to']??null,fn($q,$v)=>$q->where('posting_date','<=',$v))->selectRaw('currency,SUM(foreign_amount) AS balance,SUM(base_amount) AS base_balance')->groupBy('currency')->get();
        return response()->json([...$rows->toArray(),'balances'=>$totals,'customer'=>$customer->identity()]);
    }
    public function openInvoices(Request $r,Customer $customer): JsonResponse
    {
        $d=$r->validate(['currency'=>['required','string','size:3']]);
        return DB::transaction(function()use($d,$customer){
            Customer::lockForUpdate()->findOrFail($customer->id);
            $p=SalesInvoice::where('customer_id',$customer->id)->where('currency',$d['currency'])->where('status','posted')->orderBy('due_date')->paginate(\App\Support\PerPage::resolve(100));
            $p->through(fn($invoice)=>[...$invoice->only(['id','document_no','document_date','due_date','currency','foreign_total']),...app(CustomerLedger::class)->invoice($invoice)]);
            return response()->json($p);
        });
    }
    public function destroy(Request $r,Customer $customer,\App\Domains\Customers\Actions\DeleteCustomer $action): JsonResponse
    {
        $action->execute($customer->id,$r->user()->id);
        return response()->json(['message'=>'تم حذف العميل.']);
    }
    public function followups(Request $r,Customer $customer): JsonResponse
    {
        if($r->isMethod('post')){
            abort_unless($r->user()->hasPermission('customers.manage'),403);
            $d=$r->validate(['note'=>['required','string','max:3000'],'followup_on'=>['nullable','date_format:Y-m-d']]);
            $id=DB::table('customer_followups')->insertGetId([...$d,'customer_id'=>$customer->id,'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            app(RecordAudit::class)->execute('customers.followup_added','customer',$customer->id,null,['followup_id'=>$id],$r->user()->id);
        }
        return response()->json(DB::table('customer_followups')->where('customer_id',$customer->id)->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
    }
}
