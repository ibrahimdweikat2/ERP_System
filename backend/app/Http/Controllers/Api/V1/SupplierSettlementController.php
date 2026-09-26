<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Purchasing\Actions\PaySupplier;
use App\Domains\Purchasing\Actions\ReturnPurchase;
use App\Domains\Purchasing\Actions\SupplierBalances;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Domains\Purchasing\Models\SupplierPayment;
use App\Domains\Purchasing\Models\SupplierCreditNote;
use App\Domains\Audit\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\IdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierSettlementController extends Controller
{
    private function model(Request $r): string { return $r->route('settlementKind')==='payments'?SupplierPayment::class:SupplierCreditNote::class; }
    public function index(Request $r): JsonResponse
    {
        $d=$r->validate(['supplier_id'=>['nullable','integer'],'status'=>['nullable',Rule::in(['draft','posted'])],'search'=>['nullable','string','max:120'],'per_page'=>['nullable','integer','between:1,100']]);
        return response()->json($this->model($r)::query()->when($d['supplier_id']??null,fn($q,$id)=>$q->where('supplier_id',$id))->when($d['status']??null,fn($q,$s)=>$q->where('status',$s))->when($d['search']??null,fn($q,$s)=>$q->where('document_no','like',"%$s%"))->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
    }
    public function show(Request $r,int $id): JsonResponse { return response()->json(['data'=>$this->model($r)::with($r->route('settlementKind')==='payments'?'allocations':'lines')->findOrFail($id)]); }
    public function store(Request $r,IdempotentRequest $idem): JsonResponse
    {
        $kind=$r->route('settlementKind'); $money=['required','string',Decimal::MONEY_RULE];
        $rules=['document_date'=>['required','date_format:Y-m-d','before_or_equal:today'],'reason'=>['required','string','min:5','max:1000']];
        $rules += $kind==='payments' ? ['supplier_id'=>['required','integer','exists:suppliers,id'],'currency'=>['required','string','size:3'],'amount'=>$money,'method'=>['required',Rule::in(['cash','bank'])],'cashbox_id'=>['nullable','required_if:method,cash','integer','exists:cashboxes,id'],'bank_account_id'=>['nullable','required_if:method,bank','integer','exists:bank_accounts,id'],'payment_reference'=>['nullable','string','max:160'],'allocations'=>['sometimes','array','max:100'],'allocations.*'=>['array:supplier_invoice_id,amount'],'allocations.*.supplier_invoice_id'=>['required','integer','distinct','exists:supplier_invoices,id'],'allocations.*.amount'=>$money] : ['supplier_invoice_id'=>['required','integer','exists:supplier_invoices,id'],'lines'=>['required','array','min:1','max:100'],'lines.*'=>['array:supplier_invoice_line_id,quantity,location_id,serials'],'lines.*.supplier_invoice_line_id'=>['required','integer','distinct','exists:supplier_invoice_lines,id'],'lines.*.quantity'=>$money,'lines.*.location_id'=>['required','integer','exists:stock_locations,id'],'lines.*.serials'=>['present','array','max:1000'],'lines.*.serials.*'=>['string','max:120']];
        $data=$r->validate($rules);
        $result=$idem->execute($r->user()->id,'supplier.'.$kind.'.create',(string)$r->header('Idempotency-Key'),$data,fn()=>['id'=>app($kind==='payments'?PaySupplier::class:ReturnPurchase::class)->save($data,$r->user()->id)->id]);
        return response()->json(['data'=>$this->model($r)::findOrFail($result['id'])],201);
    }
    public function post(Request $r,int $id,IdempotentRequest $idem): JsonResponse
    {
        $kind=$r->route('settlementKind');
        $result=$idem->execute($r->user()->id,'supplier.'.$kind.'.post.'.$id,(string)$r->header('Idempotency-Key'),[],fn()=>['id'=>app($kind==='payments'?PaySupplier::class:ReturnPurchase::class)->post($id,$r->user()->id)->id]);
        return $this->show($r,$result['id']);
    }
    public function destroy(Request $r,int $id): never
    {
        $this->model($r)::findOrFail($id);
        app(RecordAudit::class)->execute('purchasing.settlement_delete_rejected','supplier_'.$r->route('settlementKind'),$id);
        throw new BusinessException('POSTED_DOCUMENT_RETAINED','مستندات الدفعات والمرتجعات محفوظة ولا تُحذف.');
    }
    public function openInvoices(Request $r): JsonResponse
    {
        $d=$r->validate(['supplier_id'=>['required','integer','exists:suppliers,id'],'currency'=>['required','string','size:3'],'page'=>['nullable','integer','min:1']]);
        return DB::transaction(function () use ($d) {
            Supplier::lockForUpdate()->findOrFail($d['supplier_id']);
            $p=SupplierInvoice::where('supplier_id',$d['supplier_id'])->where('currency',$d['currency'])->where('status','posted')->orderBy('due_date')->paginate(\App\Support\PerPage::resolve(100));
            $p->through(fn($invoice)=>[...$invoice->only(['id','document_no','supplier_invoice_no','posting_date','due_date','currency','foreign_total','base_total']),...app(SupplierBalances::class)->invoice($invoice)]);
            return response()->json($p);
        });
    }
    public function statement(Request $r,Supplier $supplier): JsonResponse
    {
        $d=$r->validate(['currency'=>['nullable','string','size:3'],'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],'page'=>['nullable','integer','min:1']]);
        $base=DB::table('supplier_ledger_entries')->where('supplier_id',$supplier->id)->when($d['currency']??null,fn($q,$v)=>$q->where('currency',$v));
        $window=(clone $base)->selectRaw('*, SUM(foreign_amount) OVER (PARTITION BY currency ORDER BY posting_date,id ROWS UNBOUNDED PRECEDING) AS running_balance, SUM(base_amount) OVER (PARTITION BY currency ORDER BY posting_date,id ROWS UNBOUNDED PRECEDING) AS running_base_balance');
        $rows=DB::query()->fromSub($window,'ledger')->when($d['from']??null,fn($q,$v)=>$q->where('posting_date','>=',$v))->when($d['to']??null,fn($q,$v)=>$q->where('posting_date','<=',$v))->orderBy('posting_date')->orderBy('id')->paginate(\App\Support\PerPage::resolve(50));
        $totals=(clone $base)->when($d['to']??null,fn($q,$v)=>$q->where('posting_date','<=',$v))->selectRaw('currency,SUM(foreign_amount) AS balance,SUM(base_amount) AS base_balance')->groupBy('currency')->get();
        return response()->json([...$rows->toArray(),'balances'=>$totals,'supplier'=>$supplier->documentIdentity()]);
    }
}
