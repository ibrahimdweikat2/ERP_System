<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Customers\Actions\SettleCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\OperationRules;
use App\Support\IdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class CustomerSettlementController extends Controller {
    private function authorizeKind(Request $r,string $kind):void{abort_unless($r->user()->hasPermission($kind==='early_settlement'?'installments.early_settlement':'sales.cancel'),403);if($kind==='early_settlement')abort_unless($r->user()->hasPermission('payments.receive'),403);}
    public function index(\Illuminate\Http\Request $r){return response()->json(DB::table('customer_settlements as s')->leftJoin('customers as u','u.id','=','s.customer_id')->select('s.*')->tap(fn($q)=>\App\Support\Search::apply($q,$r->query('search'),['s.document_no','s.reason','u.name']))->orderByDesc('s.id')->paginate(\App\Support\PerPage::resolve(25)));}
    public function show(int $id){$d=DB::table('customer_settlements')->find($id);abort_unless($d,404);$d->payload=json_decode($d->payload,true,512,JSON_THROW_ON_ERROR);return response()->json(['data'=>$d]);}
    public function store(Request $r,IdempotentRequest $idem){$d=$r->validate(['kind'=>['required',Rule::in(['refund','apply_credit','early_settlement'])],'sales_invoice_id'=>['required','integer','exists:sales_invoices,id'],'target_invoice_id'=>['nullable','required_if:kind,apply_credit','integer','exists:sales_invoices,id'],'document_date'=>OperationRules::date(),'amount'=>OperationRules::money(),'reason'=>['required','string','min:5','max:1000'],'account_id'=>['nullable','required_if:kind,early_settlement','integer','exists:accounts,id'],'method'=>['required',Rule::in(['cash','bank'])],'cashbox_id'=>['nullable','integer','exists:cashboxes,id'],'bank_account_id'=>['nullable','integer','exists:bank_accounts,id']]);$this->authorizeKind($r,$d['kind']);$result=$idem->execute($r->user()->id,'customer_settlement.create',(string)$r->header('Idempotency-Key'),$d,fn()=>['id'=>app(SettleCustomer::class)->save($d,$r->user()->id)->id]);return $this->show($result['id']);}
    public function post(Request $r,int $id,IdempotentRequest $idem){$doc=DB::table('customer_settlements')->find($id);abort_unless($doc,404);$this->authorizeKind($r,$doc->kind);$idem->execute($r->user()->id,'customer_settlement.post.'.$id,(string)$r->header('Idempotency-Key'),[],fn()=>['id'=>app(SettleCustomer::class)->post($id,$r->user()->id)->id]);return $this->show($id);}
}
