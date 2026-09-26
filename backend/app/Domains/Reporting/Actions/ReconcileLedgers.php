<?php
namespace App\Domains\Reporting\Actions;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
class ReconcileLedgers {
    public function execute():array{
        $inventory=DB::table('inventory_movements')->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN total_cost ELSE -total_cost END),0) amount")->first()->amount;
        $sources=[
            'ar'=>['ذمم العملاء',DB::table('customer_ledger_entries')->sum('base_amount'),1],
            'ap'=>['ذمم الموردين',DB::table('supplier_ledger_entries')->sum('base_amount'),-1],
            'inventory'=>['قيمة المخزون',$inventory,1],
            'checks_receivable'=>['الشيكات في الحيازة',DB::table('checks')->where('status','received')->sum('base_amount'),1],
            'checks_collection'=>['الشيكات تحت التحصيل',DB::table('checks')->whereIn('status',['deposited','under_collection'])->sum('base_amount'),1],
            'vat_input'=>['ضريبة المدخلات',DB::table('tax_transactions')->where('direction','input')->sum('recoverable_base_amount'),1],
            'vat_output'=>['ضريبة المخرجات',DB::table('tax_transactions')->where('direction','output')->sum('base_tax_amount'),-1],
        ];$rows=[];
        foreach($sources as $key=>[$label,$sub,$sign]){
            $account=DB::table('account_mappings')->where('key',$key)->value('account_id');
            $accounts=[$account];if(in_array($key,['vat_input','vat_output'])){$accounts=DB::table('tax_transactions')->where('direction',$key==='vat_input'?'input':'output')->when($key==='vat_input',fn($q)=>$q->where('tax_recoverable',true))->whereNotNull('account_id')->pluck('account_id')->push($account)->filter()->unique()->all();}
            $gl=DB::table('journal_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->where('e.status','posted')->whereIn('l.account_id',$accounts)->selectRaw('COALESCE(SUM(l.debit-l.credit),0) amount')->first()->amount;
            if($sign<0)$gl=Decimal::sub('0',$gl);$sub=(string)$sub;
            $rows[]=['key'=>$key,'name_ar'=>$label,'ledger_balance'=>Decimal::money($gl),'subledger_balance'=>Decimal::money($sub),'difference'=>Decimal::sub($gl,$sub),'as_of'=>today()->toDateString()];
        }return $rows;
    }
}
