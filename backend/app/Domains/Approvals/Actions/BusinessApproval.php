<?php
namespace App\Domains\Approvals\Actions;
use App\Domains\Audit\Actions\RecordAudit;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

class BusinessApproval
{
    public function policy(string $key): array
    {
        $p=DB::table('business_policies')->where('key',$key)->sharedLock()->first();
        if (!$p) throw new BusinessException('POLICY_MISSING','أكمل إعداد سياسة التشغيل.');
        return ['version'=>(int)$p->version,...json_decode($p->settings,true,512,JSON_THROW_ON_ERROR)];
    }
    public function hash(array $payload): string { return hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
    public function request(string $source,int $id,int $version,string $policyKey,array $payload,string $reason,int $actor): int
    {
        $policy=$this->policy($policyKey); $hash=$this->hash($payload);
        $existing=DB::table('workflow_approvals')->where(['source_type'=>$source,'source_id'=>$id,'source_version'=>$version,'payload_hash'=>$hash,'policy_version'=>$policy['version']])->whereIn('status',['pending','approved'])->lockForUpdate()->first();
        if ($existing) return $existing->id;
        $approval=DB::table('workflow_approvals')->insertGetId(['source_type'=>$source,'source_id'=>$id,'source_version'=>$version,'policy_key'=>$policyKey,'policy_version'=>$policy['version'],'payload_hash'=>$hash,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'reason'=>$reason,'requested_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
        app(RecordAudit::class)->execute('approvals.requested',$source,$id,null,['approval_id'=>$approval,'reason'=>$reason],$actor);
        // The store owner never waits on anyone: their own requests are approved on the spot.
        if (User::find($actor)?->isOwner()) {
            DB::table('workflow_approvals')->where('id',$approval)->update(['status'=>'approved','decision_reason'=>'اعتماد تلقائي — المالك','decided_by'=>$actor,'decided_at'=>now(),'updated_at'=>now()]);
            app(RecordAudit::class)->execute('approvals.approved',$source,$id,null,['approval_id'=>$approval,'reason'=>'اعتماد تلقائي — المالك'],$actor);
        }
        return $approval;
    }
    public function require(?int $id,array $payload,string $policyKey): void
    {
        $p=$this->policy($policyKey);
        // Owner-performed operations need no separate approval.
        if (auth()->user()?->isOwner()) return;
        $a=$id?DB::table('workflow_approvals')->where('id',$id)->lockForUpdate()->first():null;
        if (!$a || $a->status!=='approved' || $a->policy_key!==$policyKey || $a->policy_version!==$p['version'] || !hash_equals($a->payload_hash,$this->hash($payload))) throw new BusinessException('APPROVAL_REQUIRED','يلزم اعتماد البيانات الحالية قبل تنفيذ العملية.');
    }
    public function decide(int $id,string $decision,string $reason,int $actor): object
    {
        return DB::transaction(function () use($id,$decision,$reason,$actor) {
            $a=DB::table('workflow_approvals')->where('id',$id)->lockForUpdate()->first(); abort_unless($a,404);
            $permission=match($a->policy_key){'sales'=>'sales.override_price','installments'=>'installments.approve','treasury'=>'expenses.approve','checks'=>'checks.return_to_customer',default=>'approvals.decide'};
            if (!User::findOrFail($actor)->hasPermission($permission)) throw new BusinessException('APPROVAL_FORBIDDEN','لا تملك صلاحية اعتماد هذا النوع من العمليات.',403);
            $policy=$this->policy($a->policy_key);
            if (($policy['segregate_requester']??false) && $a->requested_by===$actor && !User::findOrFail($actor)->isOwner()) throw new BusinessException('SELF_APPROVAL_FORBIDDEN','تتطلب السياسة اعتماد مستخدم آخر.',403);
            if ($a->status===$decision) return $a;
            if ($a->status!=='pending' || $a->policy_version!==$policy['version']) throw new BusinessException('APPROVAL_STALE','تغيرت السياسة أو سبق اتخاذ القرار.',409);
            DB::table('workflow_approvals')->where('id',$id)->update(['status'=>$decision,'decision_reason'=>$reason,'decided_by'=>$actor,'decided_at'=>now(),'updated_at'=>now()]);
            app(RecordAudit::class)->execute('approvals.'.$decision,$a->source_type,$a->source_id,null,['approval_id'=>$id,'reason'=>$reason],$actor);
            return DB::table('workflow_approvals')->find($id);
        },5);
    }
}
