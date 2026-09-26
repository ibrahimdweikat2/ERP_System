<?php
namespace App\Domains\CashBank\Actions;
use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Approvals\Actions\BusinessApproval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\CashBank\Models\Cashbox;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;
class ManageCashSession {
    public function open(array $d,int $actor):object {
        return DB::transaction(function()use($d,$actor){
            $cash=Cashbox::lockForUpdate()->findOrFail($d['cashbox_id']);if(!$cash->active)throw new BusinessException('CASHBOX_INACTIVE','الصندوق غير نشط.');
            if(DB::table('cashier_sessions')->where('cashbox_id',$cash->id)->where('status','open')->lockForUpdate()->first())throw new BusinessException('SESSION_ALREADY_OPEN','للصندوق وردية مفتوحة بالفعل.');
            $system=DB::table('journal_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->where('l.account_id',$cash->account_id)->where('e.status','posted')->sum('l.foreign_amount');
            $id=DB::table('cashier_sessions')->insertGetId(['cashbox_id'=>$cash->id,'cashier_id'=>$actor,'opened_on'=>today()->toDateString(),'opening_amount'=>$d['opening_amount'],'system_opening_amount'=>$system,'reason'=>$d['reason'],'created_at'=>now(),'updated_at'=>now()]);
            app(RecordAudit::class)->execute('cash.session_opened','cashier_session',$id,null,['cashbox_id'=>$cash->id,'opening_amount'=>$d['opening_amount'],'system_opening_amount'=>$system,'reason'=>$d['reason']],$actor);return $this->detail($id);
        },5);
    }
    public function detail(int $id):object {
        $session=DB::table('cashier_sessions')->find($id);abort_unless($session,404);$cash=Cashbox::findOrFail($session->cashbox_id);
        $moves=DB::table('treasury_movements as m')->join('journal_lines as l','l.id','=','m.journal_line_id')->join('journal_entries as e','e.id','=','l.journal_entry_id')->where('m.session_id',$id)->select('m.*','e.reference_type as source_type','e.reference_id as source_id')->orderBy('m.id')->get();$expected=$session->system_opening_amount;foreach($moves as $m)$expected=Decimal::add($expected,$m->amount);
        $session->expected_now=$expected;$session->currency=$cash->currency_code;$session->cashbox_name=$cash->name;$session->movements=$moves;return $session;
    }
    public function close(int $id,array $d,int $actor):object {
        return DB::transaction(function()use($id,$d,$actor){
            $s=DB::table('cashier_sessions')->where('id',$id)->lockForUpdate()->first();abort_unless($s,404);if($s->status==='closed')return $this->detail($id);
            $cash=Cashbox::lockForUpdate()->findOrFail($s->cashbox_id);$detail=$this->detail($id);$variance=Decimal::sub($d['counted_amount'],$detail->expected_now);$p=app(BusinessApproval::class)->policy('treasury');
            $payload=['session_id'=>$id,'expected'=>$detail->expected_now,'counted'=>$d['counted_amount'],'last_movement_id'=>$detail->movements->last()?->id];
            if(Decimal::cmp($variance,'0')!==0 && Decimal::of($variance)->abs()->compareTo($p['variance_approval_threshold'])>=0){
                try{app(BusinessApproval::class)->require($s->approval_id,$payload,'treasury');}catch(BusinessException $e){
                    $a=app(BusinessApproval::class)->request('cash_session_close',$id,1,'treasury',$payload,$d['reason'],$actor);DB::table('cashier_sessions')->where('id',$id)->update(['approval_id'=>$a,'counted_amount'=>$d['counted_amount'],'expected_amount'=>$detail->expected_now,'variance'=>$variance,'reason'=>$d['reason'],'updated_at'=>now()]);$result=$this->detail($id);$result->awaiting_approval=true;return $result;
                }
            }
            if(Decimal::cmp($variance,'0')!==0){
                $date=today()->toDateString();$fx=app(CurrencySnapshot::class)->execute($cash->currency_code,$date,StoreSetting::findOrFail(1)->base_currency);$base=Decimal::mul($variance,$fx['exchange_rate']);
                app(PostSystemJournal::class)->execute('cashier_session',$id,'variance',$date,$d['reason'],[Posting::line($cash->account_id,$base,$variance),Posting::line('cash_variance',Decimal::sub('0',$base),Decimal::sub('0',$variance))],$actor,'general',$fx);
            }
            DB::table('cashier_sessions')->where('id',$id)->update(['status'=>'closed','counted_amount'=>$d['counted_amount'],'expected_amount'=>$detail->expected_now,'variance'=>$variance,'reason'=>$d['reason'],'closed_at'=>now(),'closed_by'=>$actor,'updated_at'=>now()]);app(RecordAudit::class)->execute('cash.session_closed','cashier_session',$id,null,['counted'=>$d['counted_amount'],'expected'=>$detail->expected_now,'variance'=>$variance,'reason'=>$d['reason']],$actor);return $this->detail($id);
        },5);
    }
}
