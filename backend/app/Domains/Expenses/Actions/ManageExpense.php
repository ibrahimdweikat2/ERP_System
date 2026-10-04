<?php
namespace App\Domains\Expenses\Actions;
use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Accounting\Models\Account;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Tax\Actions\CalculateDocumentTax;
use App\Domains\Tax\Models\TaxCode;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;
class ManageExpense {
    public function save(array $d,int $actor):object {
        return DB::transaction(function()use($d,$actor){
            $store=StoreSetting::sharedCurrent();$fx=app(CurrencySnapshot::class)->execute($d['currency'],$d['document_date'],$store->base_currency);
            $account=Account::sharedLock()->findOrFail($d['account_id']);if(!$account->active||$account->is_control_account||$account->account_type!=='expense')throw new BusinessException('EXPENSE_ACCOUNT_INVALID','اختر حساب مصروف نشطاً غير رقابي.');
            $tax=app(CalculateDocumentTax::class)->execute(['quantity'=>'1','unit_price'=>$d['amount'],'discount_amount'=>'0','tax_code_id'=>$d['tax_code_id'],'tax_inclusive'=>$d['tax_inclusive']],$d['document_date']);
            $taxCode=TaxCode::sharedLock()->findOrFail($d['tax_code_id']);$payload=[...$d,...$tax,'tax_account_id'=>$taxCode->input_account_id];unset($payload['currency'],$payload['document_date'],$payload['description']);
            if(Decimal::cmp($tax['total'],'0')<=0)throw new BusinessException('EXPENSE_AMOUNT_REQUIRED','قيمة المصروف يجب أن تكون موجبة.');
            $base=Decimal::add(Decimal::mul($tax['taxable_base'],$fx['exchange_rate']),Decimal::mul($tax['tax_amount'],$fx['exchange_rate']));
            $id=DB::table('expenses')->insertGetId([...$fx,'document_date'=>$d['document_date'],'amount'=>$tax['total'],'base_amount'=>$base,'description'=>$d['description'],'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
            $doc=DB::table('expenses')->find($id);$p=app(BusinessApproval::class)->policy('treasury');
            if(Decimal::cmp($base,$p['expense_approval_threshold'])>=0){$approval=app(BusinessApproval::class)->request('expense',$id,1,'treasury',$this->payload($doc),$d['description'],$actor);DB::table('expenses')->where('id',$id)->update(['approval_id'=>$approval]);}
            app(RecordAudit::class)->execute('expenses.created','expense',$id,null,['amount'=>$tax['total'],'description'=>$d['description']],$actor);return DB::table('expenses')->find($id);
        },5);
    }
    private function payload(object $d):array{return ['id'=>$d->id,'document_date'=>$d->document_date,'amount'=>$d->amount,'base_amount'=>$d->base_amount,'currency'=>$d->currency,'exchange_rate'=>$d->exchange_rate,'payload'=>json_decode($d->payload,true,512,JSON_THROW_ON_ERROR)];}
    public function post(int $id,int $actor):object {
        return DB::transaction(function()use($id,$actor){
            $d=DB::table('expenses')->where('id',$id)->lockForUpdate()->first();abort_unless($d,404);if($d->status==='posted')return $d;
            Posting::period($d->document_date);$policy=app(BusinessApproval::class)->policy('treasury');if(Decimal::cmp($d->base_amount,$policy['expense_approval_threshold'])>=0)app(BusinessApproval::class)->require($d->approval_id,$this->payload($d),'treasury');
            $p=json_decode($d->payload,true,512,JSON_THROW_ON_ERROR);$account=Account::sharedLock()->findOrFail($p['account_id']);if(!$account->active||$account->account_type!=='expense'||$account->is_control_account)throw new BusinessException('EXPENSE_ACCOUNT_INVALID','حساب المصروف غير صالح.');
            $cash=Posting::treasury($p['method'],$p[$p['method']==='cash'?'cashbox_id':'bank_account_id'],$d->currency);$net=Decimal::mul($p['taxable_base'],$d->exchange_rate);$tax=Decimal::mul($p['tax_amount'],$d->exchange_rate);$gl=[];
            if($p['tax_recoverable'] && Decimal::cmp($tax,'0')>0){$ta=Account::sharedLock()->find($p['tax_account_id']);if(!StoreSetting::current()->vat_registered||!$ta||!$ta->active||!$ta->is_control_account||$ta->account_type!=='asset')throw new BusinessException('INPUT_VAT_INVALID','استرداد الضريبة يحتاج تسجيلاً ضريبياً وحساب مدخلات صالحاً.');$gl[]=Posting::line($ta->id,$tax,$p['tax_amount']);}
            else {$net=Decimal::add($net,$tax);}
            $gl[]=Posting::line($account->id,$net,$p['tax_recoverable']?$p['taxable_base']:$d->amount);$gl[]=Posting::line($cash,Decimal::sub('0',$d->base_amount),Decimal::sub('0',$d->amount));
            $entry=app(PostSystemJournal::class)->execute('expense',$id,'expense',$d->document_date,$d->description,$gl,$actor,'payments',(array)$d);$number=app(NextDocumentNumber::class)->execute('expense',$d->document_date);
            DB::table('tax_transactions')->insert(['source_type'=>'expense','source_id'=>$id,'source_line_id'=>$id,'event'=>'expense','direction'=>'input','document_date'=>$d->document_date,'posting_date'=>$d->document_date,'tax_code_id'=>$p['tax_code_id'],'tax_snapshot'=>json_encode($p['tax_snapshot'],JSON_THROW_ON_ERROR),'currency'=>$d->currency,'exchange_rate'=>$d->exchange_rate,'tax_rate'=>$p['tax_rate'],'tax_inclusive'=>$p['tax_inclusive'],'tax_recoverable'=>$p['tax_recoverable'],'taxable_base'=>$p['taxable_base'],'tax_amount'=>$p['tax_amount'],'base_taxable_amount'=>Decimal::mul($p['taxable_base'],$d->exchange_rate),'base_tax_amount'=>$tax,'recoverable_base_amount'=>$p['tax_recoverable']?$tax:'0','account_id'=>$p['tax_recoverable']?$p['tax_account_id']:$account->id,'journal_entry_id'=>$entry->id,'created_by'=>$actor,'created_at'=>now()]);
            DB::table('expenses')->where('id',$id)->update(['status'=>'posted','document_no'=>$number,'posted_at'=>now(),'posted_by'=>$actor,'posted_journal_entry_id'=>$entry->id,'updated_at'=>now()]);app(RecordAudit::class)->execute('expenses.posted','expense',$id,null,['document_no'=>$number,'amount'=>$d->amount,'journal_entry_id'=>$entry->id],$actor);return DB::table('expenses')->find($id);
        },5);
    }
}
