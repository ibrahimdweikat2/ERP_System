<?php
namespace App\Domains\Customers\Actions;
use App\Domains\Sales\Models\SalesInvoice;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;

class CustomerLedger
{
    public function record(int $customer,string $source,int $id,string $event,string $number,string $date,string $currency,string $foreign,string $base,string $rate,?int $journal,int $actor,?string $due=null): void
    {
        DB::table('customer_ledger_entries')->insert(['customer_id'=>$customer,'source_type'=>$source,'source_id'=>$id,'event'=>$event,'document_no'=>$number,'posting_date'=>$date,'due_date'=>$due,'currency'=>$currency,'foreign_amount'=>$foreign,'base_amount'=>$base,'exchange_rate'=>$rate,'account_id'=>Posting::account('ar','asset'),'journal_entry_id'=>$journal,'created_by'=>$actor,'created_at'=>now()]);
    }
    public function invoice(SalesInvoice $invoice): array
    {
        $rows=DB::table('payment_allocations')->where('sales_invoice_id',$invoice->id)->lockForUpdate()->get();
        $returns=DB::table('sales_returns')->where('sales_invoice_id',$invoice->id)->where('status','posted')->lockForUpdate()->get();
        $amount='0'; $base='0';
        foreach($rows->concat($returns) as $row){ $amount=Decimal::add($amount,$row->amount); $base=Decimal::add($base,$row->base_amount); }
        // Instrument reversals reopen precisely the original payment allocations.
        if (\Illuminate\Support\Facades\Schema::hasTable('payment_allocation_reversals')) {
            foreach(DB::table('payment_allocation_reversals')->where('sales_invoice_id',$invoice->id)->lockForUpdate()->get() as $row){ $amount=Decimal::sub($amount,$row->amount); $base=Decimal::sub($base,$row->base_amount); }
        }
        foreach(DB::table('customer_settlement_allocations')->where('sales_invoice_id',$invoice->id)->lockForUpdate()->get() as $row){$amount=Decimal::sub($amount,$row->amount);$base=Decimal::sub($base,$row->base_amount);}
        foreach(DB::table('sales_return_concessions')->where('sales_invoice_id',$invoice->id)->lockForUpdate()->get() as $row){$amount=Decimal::sub($amount,$row->amount);$base=Decimal::sub($base,$row->base_amount);}
        return ['settled'=>$amount,'settled_base'=>$base,'remaining'=>Decimal::sub($invoice->foreign_total,$amount),'remaining_base'=>Decimal::sub($invoice->base_total,$base)];
    }
}
