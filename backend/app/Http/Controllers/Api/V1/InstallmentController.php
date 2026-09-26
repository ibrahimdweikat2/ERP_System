<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Installments\Actions\GenerateSchedule;
use App\Domains\Installments\Actions\ManageInstallmentContract;
use App\Domains\Installments\Actions\CreditDecision;
use App\Domains\Customers\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\OperationRules;
use App\Support\IdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class InstallmentController extends Controller {
    public function index(Request $r):JsonResponse{$d=$r->validate(['customer_id'=>['nullable','integer'],'status'=>['nullable',Rule::in(['active','completed','overdue'])]]);return response()->json(DB::table('installment_contracts as c')->join('customers as u','u.id','=','c.customer_id')->when($d['customer_id']??null,fn($q,$v)=>$q->where('customer_id',$v))->when($d['status']??null,fn($q,$v)=>$v==='overdue'
            // Derived: an active contract with at least one past-due, unpaid installment.
            ?$q->where('c.status','active')->whereExists(fn($x)=>$x->from('installment_schedule as o')->whereColumn('o.contract_id','c.id')->where('o.superseded',false)->where('o.due_date','<',today()->toDateString())->whereRaw('o.amount-o.paid_amount-o.adjustment_amount>0'))
            :$q->where('c.status',$v))->tap(fn($q)=>\App\Support\Search::apply($q,$r->query('search'),['c.document_no','u.name','u.phone']))->orderByDesc('c.id')->select('c.*','u.name as customer_name')->selectSub(DB::table('installment_schedule as s')->whereColumn('s.contract_id','c.id')->where('s.superseded',false)->selectRaw('COALESCE(SUM(s.amount-s.paid_amount-s.adjustment_amount),0)'),'remaining')->paginate(\App\Support\PerPage::resolve(25)));}
    public function receiveChecks(Request $r,int $id,IdempotentRequest $idem):JsonResponse{
        $d=$r->validate(['received_date'=>OperationRules::date(),'bank_name'=>['required','string','max:120'],'bank_branch'=>['nullable','string','max:120'],'payer_name'=>['required','string','max:200'],'account_reference'=>['required','string','max:120'],'notes'=>['nullable','string','max:3000'],
            'checks'=>['required','array','min:1','max:120'],'checks.*.check_no'=>['required','string','max:80','distinct'],'checks.*.amount'=>OperationRules::money(),'checks.*.due_date'=>['required','date_format:Y-m-d'],'checks.*.issue_date'=>['nullable','date_format:Y-m-d'],'checks.*.installment_schedule_id'=>['nullable','integer','exists:installment_schedule,id']]);
        $result=$idem->execute($r->user()->id,'installment.checks.'.$id,(string)$r->header('Idempotency-Key'),$d,fn()=>['ids'=>app(\App\Domains\Checks\Actions\ReceiveInstallmentChecks::class)->execute($id,$d,$r->user()->id)]);
        return response()->json(['data'=>['check_ids'=>$result['ids'],...app(ManageInstallmentContract::class)->schedule($id)]],201);
    }
    public function show(int $id):JsonResponse{return response()->json(['data'=>app(ManageInstallmentContract::class)->schedule($id)]);}
    public function preview(Request $r):JsonResponse{$d=$r->validate(['amount'=>OperationRules::money(),...OperationRules::schedule()]);return response()->json(['data'=>app(GenerateSchedule::class)->execute($d['amount'],$d)]);}
    public function credit(Customer $customer):JsonResponse{return DB::transaction(function()use($customer){$c=Customer::lockForUpdate()->findOrFail($customer->id);return response()->json(['data'=>app(CreditDecision::class)->summary($c)]);});}
    public function due(Request $r):JsonResponse{
        $d=$r->validate(['from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d'],'overdue'=>['nullable','boolean'],'customer_id'=>['nullable','integer']]);
        $q=DB::table('installment_schedule as s')->join('installment_contracts as c','c.id','=','s.contract_id')->join('customers as u','u.id','=','c.customer_id')->where('s.superseded',false)->whereRaw('s.amount>s.paid_amount+s.adjustment_amount')->when($d['from']??null,fn($q,$v)=>$q->where('s.due_date','>=',$v))->when($d['to']??null,fn($q,$v)=>$q->where('s.due_date','<=',$v))->when($d['overdue']??false,fn($q)=>$q->whereRaw("DATE_ADD(s.due_date, INTERVAL COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(c.policy_snapshot, '$.grace_days')) AS UNSIGNED),0) DAY) < ?",[today()->toDateString()]))->when($d['customer_id']??null,fn($q,$v)=>$q->where('c.customer_id',$v));
        return response()->json($q->orderBy('s.due_date')->select('s.*','c.document_no','c.customer_id','c.currency','u.name as customer_name','u.phone')->selectRaw('s.amount-s.paid_amount-s.adjustment_amount AS remaining')->paginate(\App\Support\PerPage::resolve(50)));
    }
    public function reschedule(Request $r,int $id,IdempotentRequest $idem):JsonResponse{$d=$r->validate(['reason'=>['required','string','min:5','max:1000'],...OperationRules::schedule()]);$result=$idem->execute($r->user()->id,'installment.reschedule.'.$id,(string)$r->header('Idempotency-Key'),$d,fn()=>['id'=>app(ManageInstallmentContract::class)->requestReschedule($id,$d,$r->user()->id)->id]);return response()->json(['data'=>DB::table('installment_reschedules')->find($result['id'])]);}
    public function apply(Request $r,int $id,IdempotentRequest $idem):JsonResponse{$result=$idem->execute($r->user()->id,'reschedule.apply.'.$id,(string)$r->header('Idempotency-Key'),[],fn()=>app(ManageInstallmentContract::class)->applyReschedule($id,$r->user()->id));return response()->json(['data'=>$result]);}
}
