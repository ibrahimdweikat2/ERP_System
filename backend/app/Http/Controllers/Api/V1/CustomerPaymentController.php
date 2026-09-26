<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Payments\Actions\ReceiveCustomerPayment;
use App\Domains\Payments\Models\CustomerPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\OperationRules;
use App\Support\IdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
class CustomerPaymentController extends Controller {
    public function index(Request $r):JsonResponse{$d=$r->validate(['customer_id'=>['nullable','integer'],'per_page'=>['nullable','integer','between:1,100']]);return response()->json(CustomerPayment::when($d['customer_id']??null,fn($q,$v)=>$q->where('customer_id',$v))->tap(fn($q)=>\App\Support\Search::apply($q,$r->query('search'),['document_no','payment_reference','customer_snapshot->name']))->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));}
    public function show(CustomerPayment $customerPayment):JsonResponse{return response()->json(['data'=>$customerPayment->load('allocations')]);}
    public function store(Request $r,IdempotentRequest $idem):JsonResponse{$d=$r->validate(OperationRules::payment());$result=$idem->execute($r->user()->id,'customer_payment.create',(string)$r->header('Idempotency-Key'),$d,fn()=>['id'=>app(ReceiveCustomerPayment::class)->save($d,$r->user()->id)->id]);return $this->show(CustomerPayment::findOrFail($result['id']));}
    public function post(Request $r,CustomerPayment $customerPayment,IdempotentRequest $idem):JsonResponse{$result=$idem->execute($r->user()->id,'customer_payment.post.'.$customerPayment->id,(string)$r->header('Idempotency-Key'),[],fn()=>['id'=>app(ReceiveCustomerPayment::class)->post($customerPayment->id,$r->user()->id)->id]);return $this->show(CustomerPayment::findOrFail($result['id']));}
}
