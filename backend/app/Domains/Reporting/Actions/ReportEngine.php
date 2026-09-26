<?php
namespace App\Domains\Reporting\Actions;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportEngine {
    public const REPORTS=[
        'sales'=>['المبيعات','sales.view'], 'purchases'=>['المشتريات','purchasing.view'],
        'inventory'=>['المخزون حسب الموقع','inventory.view'], 'inventory-valuation'=>['تقييم المخزون','inventory.view_cost'],
        'receivables'=>['أعمار ذمم العملاء','reports.financial'], 'payables'=>['أعمار ذمم الموردين','reports.financial'],
        'installments'=>['الأقساط والتحصيل','installments.view'], 'checks'=>['الشيكات والتدفق المتوقع','checks.view'],
        'trial-balance'=>['ميزان المراجعة','reports.financial'], 'general-ledger'=>['الأستاذ العام','reports.financial'],
        'income-statement'=>['قائمة الدخل','reports.financial'], 'balance-sheet'=>['الميزانية العمومية','reports.financial'],
        'vat'=>['سجل ضريبة القيمة المضافة','reports.vat'], 'vat-summary'=>['ملخص الضريبة','reports.vat'],
        'profitability'=>['الربحية حسب المنتج','reports.financial'], 'cash-movements'=>['حركات الصندوق والبنك','cashbank.view'],
        'reconciliation'=>['مطابقة الحسابات الرقابية','reports.financial'], 'receipts-uninvoiced'=>['الاستلام غير المفوتر','purchasing.view'],
    ];
    public function authorize(string $report,User $user):void{if(!isset(self::REPORTS[$report]))abort(404);if(!$user->hasPermission(self::REPORTS[$report][1]))throw new BusinessException('REPORT_FORBIDDEN','لا تملك صلاحية عرض هذا التقرير.',403);}
    public function financialSummary(string $report,array $filters):array{
        if(!in_array($report,['trial-balance','income-statement','balance-sheet']))return [];
        $values=['asset'=>'0','liability'=>'0','equity'=>'0','revenue'=>'0','expense'=>'0'];$debit='0';$credit='0';
        foreach($this->query($report,$filters)->get() as $row){$values[$row->account_type]=\App\Support\Decimal::add($values[$row->account_type],$row->balance);$debit=\App\Support\Decimal::add($debit,$row->debit);$credit=\App\Support\Decimal::add($credit,$row->credit);}
        $d=\App\Support\Decimal::class;$income=$d::sub('0',$values['revenue']);$profit=$d::sub($income,$values['expense']);
        if($report==='income-statement')return ['الإيرادات'=>$income,'المصروفات'=>$values['expense'],'صافي النتيجة'=>$profit];
        if($report==='balance-sheet'){$liabilities=$d::sub('0',$values['liability']);$equity=$d::add($d::sub('0',$values['equity']),$profit);return ['الأصول'=>$values['asset'],'الالتزامات'=>$liabilities,'حقوق الملكية شاملة النتيجة'=>$equity,'فرق الميزانية'=>$d::sub($values['asset'],$d::add($liabilities,$equity))];}
        return ['إجمالي المدين'=>$debit,'إجمالي الدائن'=>$credit,'الفرق'=>$d::sub($debit,$credit)];
    }
    public function query(string $report,array $f):Builder{
        $from=$f['from']??date('Y-01-01');$to=$f['to']??today()->toDateString();
        if(in_array($report,['trial-balance','income-statement','balance-sheet'])){
            $lines=DB::table('journal_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->where('e.status','posted')->where('e.entry_date','<=',$to)->when($report==='income-statement',fn($q)=>$q->where('e.entry_date','>=',$from))->groupBy('l.account_id')->selectRaw('l.account_id,SUM(l.debit) debit,SUM(l.credit) credit');
            if($report==='trial-balance')$lines->selectRaw('SUM(CASE WHEN e.entry_date < ? THEN l.debit-l.credit ELSE 0 END) AS opening_balance,SUM(CASE WHEN e.entry_date >= ? THEN l.debit ELSE 0 END) AS period_debit,SUM(CASE WHEN e.entry_date >= ? THEN l.credit ELSE 0 END) AS period_credit',[$from,$from,$from]);
            $query=DB::table('accounts as a')->leftJoinSub($lines,'l','l.account_id','=','a.id')->when($report==='income-statement',fn($q)=>$q->whereIn('a.account_type',['revenue','expense']))->select('a.id','a.code','a.name_ar','a.account_type','a.normal_balance')->selectRaw('COALESCE(l.debit,0) AS debit,COALESCE(l.credit,0) AS credit,COALESCE(l.debit,0)-COALESCE(l.credit,0) AS balance')->orderBy('a.code');if($report==='trial-balance')$query->selectRaw('COALESCE(l.opening_balance,0) AS opening_balance,COALESCE(l.period_debit,0) AS period_debit,COALESCE(l.period_credit,0) AS period_credit');return $query;
        }
        if($report==='general-ledger')return DB::table('journal_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->join('accounts as a','a.id','=','l.account_id')->where('e.status','posted')->whereBetween('e.entry_date',[$from,$to])->when($f['account_id']??null,fn($q,$v)=>$q->where('a.id',$v))->select('l.id','e.entry_date','e.entry_no','a.code','a.name_ar','l.debit','l.credit','l.currency','l.foreign_amount','l.exchange_rate','e.reference_type','e.reference_id','e.description')->orderBy('e.entry_date')->orderBy('l.id');
        if($report==='sales')return DB::table('sales_invoices')->where('status','posted')->whereBetween('document_date',[$from,$to])->select('id','document_no','document_date','customer_id','sale_mode','currency','net_total','tax_total','foreign_total','base_total')->orderByDesc('id');
        if($report==='purchases')return DB::table('supplier_invoices')->where('status','posted')->whereBetween('posting_date',[$from,$to])->select('id','document_no','supplier_invoice_no','posting_date','supplier_id','currency','net_total','tax_total','foreign_total','base_total')->orderByDesc('id');
        if(in_array($report,['inventory','inventory-valuation'])){
            $q=DB::table('inventory_movements as m')->join('products as p','p.id','=','m.product_id')->join('stock_locations as l','l.id','=','m.location_id')->where('m.movement_date','<=',$to)->when($f['location_id']??null,fn($q,$v)=>$q->where('l.id',$v))->select('p.id','p.sku','p.name_ar','l.name_ar as location')->selectRaw("SUM(CASE WHEN m.direction='in' THEN m.quantity ELSE -m.quantity END) AS quantity")->groupBy('p.id','p.sku','p.name_ar','l.id','l.name_ar')->orderBy('p.sku')->orderBy('l.id');
            if($report==='inventory-valuation')$q->selectRaw("SUM(CASE WHEN m.direction='in' THEN m.total_cost ELSE -m.total_cost END) AS base_value");return $q;
        }
        if(in_array($report,['receivables','payables']))return $this->aging($report==='receivables',$to);
        if($report==='installments')return DB::table('installment_schedule as s')->join('installment_contracts as c','c.id','=','s.contract_id')->join('customers as u','u.id','=','c.customer_id')->where('s.superseded',false)->whereBetween('s.due_date',[$from,$to])->select('s.id','c.document_no','u.name as customer','c.currency','s.due_date','s.amount','s.paid_amount','s.adjustment_amount')->selectRaw('s.amount-s.paid_amount-s.adjustment_amount AS remaining')->orderBy('s.due_date')->orderBy('s.id');
        if($report==='checks')return DB::table('checks as c')->join('customers as u','u.id','=','c.customer_id')->whereBetween('c.due_date',[$from,$to])->when($f['status']??null,fn($q,$v)=>$q->where('c.status',$v))->select('c.id','c.check_no','u.name as customer','c.bank_name','c.payer_name','c.due_date','c.currency','c.amount','c.base_amount','c.status','c.return_reason')->orderBy('c.due_date')->orderBy('c.id');
        if($report==='vat')return DB::table('tax_transactions')->whereBetween('posting_date',[$from,$to])->select('id','posting_date','document_date','source_type','source_id','direction','currency','tax_rate','taxable_base','tax_amount','base_taxable_amount','base_tax_amount','recoverable_base_amount','journal_entry_id')->orderBy('posting_date')->orderBy('id');
        if($report==='vat-summary')return DB::table('tax_transactions as t')->join('tax_codes as c','c.id','=','t.tax_code_id')->whereBetween('t.posting_date',[$from,$to])->groupBy('t.direction','c.code','c.category','t.tax_rate')->select('t.direction','c.code','c.category','t.tax_rate')->selectRaw('SUM(t.base_taxable_amount) AS base_net,SUM(t.base_tax_amount) AS base_tax,SUM(t.recoverable_base_amount) AS recoverable')->orderBy('t.direction')->orderBy('c.code');
        if($report==='profitability'){
            $sales=DB::table('sales_invoice_lines as l')->join('sales_invoices as i','i.id','=','l.sales_invoice_id')->where('i.status','posted')->whereBetween('i.document_date',[$from,$to])->selectRaw('l.product_id,l.quantity,l.base_net,l.cogs');
            $returns=DB::table('sales_return_lines as r')->join('sales_returns as i','i.id','=','r.sales_return_id')->join('sales_invoice_lines as l','l.id','=','r.sales_invoice_line_id')->where('i.status','posted')->whereBetween('i.document_date',[$from,$to])->selectRaw('l.product_id,-r.quantity AS quantity,-r.base_net AS base_net,-r.cogs AS cogs');
            return DB::query()->fromSub($sales->unionAll($returns),'x')->join('products as p','p.id','=','x.product_id')->select('p.id','p.sku','p.name_ar')->selectRaw('SUM(x.quantity) AS quantity,SUM(x.base_net) AS revenue,SUM(x.cogs) AS cogs,SUM(x.base_net-x.cogs) AS gross_profit')->groupBy('p.id','p.sku','p.name_ar')->orderBy('p.sku');
        }
        if($report==='cash-movements')return DB::table('treasury_movements as m')->join('accounts as a','a.id','=','m.account_id')->whereBetween('m.document_date',[$from,$to])->select('m.id','m.document_date','a.name_ar','m.currency','m.amount','m.base_amount','m.session_id','m.journal_line_id')->orderBy('m.document_date')->orderBy('m.id');
        if($report==='receipts-uninvoiced'){
            $used=DB::table('supplier_invoice_lines as l')->join('supplier_invoices as i','i.id','=','l.supplier_invoice_id')->where('i.status','posted')->where('i.posting_date','<=',$to)->groupBy('l.goods_receipt_line_id')->selectRaw('l.goods_receipt_line_id,SUM(l.quantity) quantity');
            return DB::table('goods_receipt_lines as l')->join('goods_receipts as g','g.id','=','l.goods_receipt_id')->join('products as p','p.id','=','l.product_id')->leftJoinSub($used,'u','u.goods_receipt_line_id','=','l.id')->where('g.status','posted')->where('g.document_date','<=',$to)->whereRaw('l.quantity>COALESCE(u.quantity,0)')->select('l.id','g.id as receipt_id','g.document_no','g.document_date','g.supplier_id','p.sku','p.name_ar','g.currency','l.quantity')->selectRaw('l.quantity-COALESCE(u.quantity,0) AS remaining')->orderBy('l.id');
        }
        throw new BusinessException('REPORT_INVALID','نوع التقرير غير مدعوم.');
    }
    private function aging(bool $ar,string $to):Builder{
        $invoice=$ar?'sales_invoices':'supplier_invoices';$payments=$ar?'payment_allocations':'supplier_payment_allocations';$paymentTable=$ar?'customer_payments':'supplier_payments';$payFk=$ar?'customer_payment_id':'supplier_payment_id';$invoiceFk=$ar?'sales_invoice_id':'supplier_invoice_id';$returns=$ar?'sales_returns':'supplier_credit_notes';$partyFk=$ar?'customer_id':'supplier_id';$party=$ar?'customers':'suppliers';$date=$ar?'document_date':'posting_date';$name=$ar?'name':'legal_name';
        $p=DB::table($payments.' as a')->join($paymentTable.' as p','p.id','=','a.'.$payFk)->where('p.status','posted')->where('p.document_date','<=',$to)->groupBy('a.'.$invoiceFk)->selectRaw("a.$invoiceFk invoice_id,SUM(a.amount) amount,SUM(a.base_amount) base_amount");
        $r=DB::table($returns)->where('status','posted')->where('document_date','<=',$to)->groupBy($invoiceFk)->selectRaw("$invoiceFk invoice_id,SUM(amount) amount,SUM(base_amount) base_amount");
        $q=DB::table($invoice.' as i')->join($party.' as u','u.id','=','i.'.$partyFk)->leftJoinSub($p,'p','p.invoice_id','=','i.id')->leftJoinSub($r,'r','r.invoice_id','=','i.id')->where('i.status','posted')->where('i.'.$date,'<=',$to);
        $reversed='0';$reversedBase='0';if($ar){$rev=DB::table('payment_allocation_reversals')->where('document_date','<=',$to)->groupBy('sales_invoice_id')->selectRaw('sales_invoice_id,SUM(amount) amount,SUM(base_amount) base_amount');$q->leftJoinSub($rev,'v','v.sales_invoice_id','=','i.id');$reversed='COALESCE(v.amount,0)';$reversedBase='COALESCE(v.base_amount,0)';}
        $adjust='0';$adjustBase='0';if($ar){$adj=DB::table('customer_settlement_allocations')->where('document_date','<=',$to)->groupBy('sales_invoice_id')->selectRaw('sales_invoice_id,SUM(amount) amount,SUM(base_amount) base_amount');$q->leftJoinSub($adj,'z','z.sales_invoice_id','=','i.id');$con=DB::table('sales_return_concessions')->where('document_date','<=',$to)->groupBy('sales_invoice_id')->selectRaw('sales_invoice_id,SUM(amount) amount,SUM(base_amount) base_amount');$q->leftJoinSub($con,'cr','cr.sales_invoice_id','=','i.id');$adjust='COALESCE(z.amount,0)+COALESCE(cr.amount,0)';$adjustBase='COALESCE(z.base_amount,0)+COALESCE(cr.base_amount,0)';}
        $remaining="i.foreign_total-COALESCE(p.amount,0)-COALESCE(r.amount,0)+$reversed+$adjust";$base="i.base_total-COALESCE(p.base_amount,0)-COALESCE(r.base_amount,0)+$reversedBase+$adjustBase";
        return $q->select('i.id','i.document_no',"u.$name as party",'i.currency','i.due_date','i.foreign_total')->selectRaw("$remaining AS remaining,$base AS base_remaining,CASE WHEN ?<=i.due_date THEN 'current' WHEN DATEDIFF(?,i.due_date)<=30 THEN '1–30' WHEN DATEDIFF(?,i.due_date)<=60 THEN '31–60' WHEN DATEDIFF(?,i.due_date)<=90 THEN '61–90' ELSE '90+' END AS aging_bucket",[$to,$to,$to,$to])->whereRaw("($remaining)<>0")->orderBy('i.due_date')->orderBy('i.id');
    }
}
