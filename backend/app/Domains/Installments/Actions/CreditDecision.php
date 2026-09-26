<?php
namespace App\Domains\Installments\Actions;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Customers\Models\Customer;
use App\Domains\Sales\Models\SalesInvoice;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\CarbonImmutable;
class CreditDecision {
    public function summary(Customer $customer):array {
        $balance='0';foreach(DB::table('customer_ledger_entries')->where('customer_id',$customer->id)->lockForUpdate()->get(['base_amount']) as $l)$balance=Decimal::add($balance,$l->base_amount);
        $contracts=DB::table('installment_contracts')->where('customer_id',$customer->id)->where('status','active')->lockForUpdate()->get();
        $overdue='0';$days=0;
        foreach(DB::table('installment_schedule')->whereIn('contract_id',$contracts->pluck('id'))->where('superseded',false)->where('due_date','<',today()->toDateString())->lockForUpdate()->get() as $row){
            $remaining=Decimal::sub(Decimal::sub($row->amount,$row->paid_amount),$row->adjustment_amount);
            if(Decimal::cmp($remaining,'0')>0 && $row->due_date<today()->subDays((int)(json_decode($contracts->firstWhere('id',$row->contract_id)->policy_snapshot,true)['grace_days']??0))->toDateString()){$overdue=Decimal::add($overdue,Decimal::mul($remaining,$contracts->firstWhere('id',$row->contract_id)->exchange_rate));$days=max($days,(int)CarbonImmutable::parse($row->due_date)->diffInDays(today()));}
        }
        $checks='0';$bounced=0;
        foreach(SalesInvoice::where('customer_id',$customer->id)->where('status','posted')->where('sale_mode','credit')->where('due_date','<',today()->toDateString())->lockForUpdate()->get() as $invoice){$b=app(\App\Domains\Customers\Actions\CustomerLedger::class)->invoice($invoice);if(Decimal::cmp($b['remaining'],'0')>0){$overdue=Decimal::add($overdue,$b['remaining_base']);$days=max($days,(int)CarbonImmutable::parse($invoice->due_date)->diffInDays(today()));}}
        if(Schema::hasTable('checks'))foreach(DB::table('checks')->where('customer_id',$customer->id)->lockForUpdate()->get() as $c){if(in_array($c->status,['received','deposited','under_collection']))$checks=Decimal::add($checks,$c->base_amount);if($c->status==='bounced')$bounced++;}
        return ['ar_base'=>$balance,'held_checks_base'=>$checks,'exposure_base'=>Decimal::add($balance,$checks),'overdue_base'=>$overdue,'oldest_overdue_days'=>$days,'active_contracts'=>$contracts->count(),'bounced_checks'=>$bounced,'credit_limit'=>$customer->credit_limit,'risk_flag'=>$customer->risk_flag];
    }
    public function evaluate(SalesInvoice $doc,Customer $customer):array {
        $summary=$this->summary($customer);$p=app(BusinessApproval::class)->policy('installments');$down=$doc->checkout['amount']??'0';$financed=Decimal::sub($doc->foreign_total,$down);$reasons=[];
        if($customer->risk_flag!=='normal')$reasons[]='علامة مخاطر على العميل';
        if(Decimal::cmp(Decimal::add($summary['exposure_base'],Decimal::mul($financed,$doc->exchange_rate)),$customer->credit_limit)>0)$reasons[]='تجاوز الحد الائتماني';
        if($summary['oldest_overdue_days']>$customer->max_overdue_days)$reasons[]='أقساط متأخرة تتجاوز السياسة';
        if($doc->sale_mode==='installment' && $summary['active_contracts'] >= $customer->max_active_contracts)$reasons[]='تجاوز عدد العقود النشطة';
        if($summary['bounced_checks']>0)$reasons[]='شيكات مرتجعة غير مسوّاة';
        if(Decimal::cmp(Decimal::mul($down,'100'),Decimal::mul($doc->foreign_total,$p['minimum_down_payment_percent']))<0)$reasons[]='الدفعة الأولى أقل من السياسة';
        return ['summary'=>$summary,'reasons'=>$reasons,'payload'=>['sale_id'=>$doc->id,'version'=>$doc->version,'customer_id'=>$customer->id,'foreign_total'=>$doc->foreign_total,'exchange_rate'=>$doc->exchange_rate,'financed'=>$financed,'checkout'=>$doc->checkout,'summary'=>$summary,'policy_version'=>$p['version']]];
    }
    public function assertSale(SalesInvoice $doc,Customer $customer,int $actor):void {
        $p=app(BusinessApproval::class)->policy('installments');
        if($doc->sale_mode==='installment' && $p['markup_recognition']!=='full_price_at_sale')throw new BusinessException('INSTALLMENT_POLICY_REQUIRED','اعتمد سياسة إثبات سعر التقسيط في إعدادات التشغيل.');
        $result=$this->evaluate($doc,$customer);
        if(count($result['reasons'])){
            if(!$p['allow_credit_override'])throw new BusinessException('CREDIT_BLOCKED',implode('، ',$result['reasons']));
            app(BusinessApproval::class)->require($doc->credit_approval_id,$result['payload'],'installments');
        }
    }
}
