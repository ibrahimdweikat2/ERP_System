<?php
namespace App\Domains\CashBank\Actions;
use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;
class TransferCash {
    public function save(array $d,int $actor):object {
        return DB::transaction(function()use($d,$actor){
            $fx=app(CurrencySnapshot::class)->execute($d['currency'],$d['document_date'],StoreSetting::findOrFail(1)->base_currency);if(Decimal::cmp($d['amount'],'0')<=0)throw new BusinessException('AMOUNT_REQUIRED','أدخل مبلغاً موجباً.');
            $id=DB::table('cash_transfers')->insertGetId([...$fx,'document_date'=>$d['document_date'],'amount'=>$d['amount'],'base_amount'=>Decimal::mul($d['amount'],$fx['exchange_rate']),'description'=>$d['description'],'payload'=>json_encode($d,JSON_THROW_ON_ERROR),'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
            $doc=DB::table('cash_transfers')->find($id);$p=app(BusinessApproval::class)->policy('treasury');if(Decimal::cmp($doc->base_amount,$p['expense_approval_threshold'])>=0){$a=app(BusinessApproval::class)->request('cash_transfer',$id,1,'treasury',$this->payload($doc),$d['description'],$actor);DB::table('cash_transfers')->where('id',$id)->update(['approval_id'=>$a]);}app(RecordAudit::class)->execute('cash.transfer_created','cash_transfer',$id,null,$d,$actor);return DB::table('cash_transfers')->find($id);
        },5);
    }
    private function payload(object $d):array{return ['id'=>$d->id,'currency'=>$d->currency,'exchange_rate'=>$d->exchange_rate,'base_amount'=>$d->base_amount,'payload'=>json_decode($d->payload,true,512,JSON_THROW_ON_ERROR)];}
    public function post(int $id,int $actor):object {
        return DB::transaction(function()use($id,$actor){
            $doc=DB::table('cash_transfers')->where('id',$id)->lockForUpdate()->first();abort_unless($doc,404);if($doc->status==='posted')return $doc;Posting::period($doc->document_date);$p=json_decode($doc->payload,true,512,JSON_THROW_ON_ERROR);$policy=app(BusinessApproval::class)->policy('treasury');if(Decimal::cmp($doc->base_amount,$policy['expense_approval_threshold'])>=0)app(BusinessApproval::class)->require($doc->approval_id,$this->payload($doc),'treasury');
            $from=$p['kind']==='capital'?Posting::account('capital','equity'):Posting::treasury($p['from_method'],(int)$p['from_id'],$doc->currency);
            $to=$p['kind']==='drawings'?Posting::account('drawings','equity'):Posting::treasury($p['to_method'],(int)$p['to_id'],$doc->currency);
            if($from===$to)throw new BusinessException('TRANSFER_SAME_ACCOUNT','حساب المصدر والوجهة متطابقان.');
            $entry=app(PostSystemJournal::class)->execute('cash_transfer',$id,'transfer',$doc->document_date,$doc->description,[Posting::line($to,$doc->base_amount,$doc->amount),Posting::line($from,Decimal::sub('0',$doc->base_amount),Decimal::sub('0',$doc->amount))],$actor,'bank',(array)$doc);$number=app(NextDocumentNumber::class)->execute('payment_voucher',$doc->document_date);DB::table('cash_transfers')->where('id',$id)->update(['status'=>'posted','document_no'=>$number,'posted_at'=>now(),'posted_by'=>$actor,'posted_journal_entry_id'=>$entry->id,'updated_at'=>now()]);app(RecordAudit::class)->execute('cash.transfer_posted','cash_transfer',$id,null,['document_no'=>$number,'journal_entry_id'=>$entry->id],$actor);return DB::table('cash_transfers')->find($id);
        },5);
    }
}
