<?php
namespace App\Domains\Notifications\Jobs;
use App\Domains\Platform\Models\Company;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
class RefreshOperationalAlerts implements ShouldQueue {
    use Dispatchable,InteractsWithQueue,Queueable;
    public int $tries=3;public int $timeout=120;public int $backoff=60;
    // Scheduled once for the platform; each active company gets its own alerts on its own calendar day.
    // Failed background jobs are platform infrastructure and appear on the platform health page instead.
    public function handle(CompanyContext $context):void{
        foreach(Company::where('status',Company::ACTIVE)->orderBy('id')->pluck('id') as $companyId){
            try{$context->run($companyId,fn()=>$this->refresh());}catch(\Throwable $e){report($e);}
        }
    }
    private function refresh():void{
        $today=now(StoreSetting::current()->timezone)->toDateString();$items=[
            ['installments','installments.view','أقساط تحتاج متابعة',DB::table('installment_schedule')->where('superseded',false)->where('due_date','<=',$today)->whereRaw('amount>paid_amount+adjustment_amount')->count(),'/installments/overdue'],
            ['checks','checks.view','شيكات مستحقة',DB::table('checks')->where('status','received')->where('due_date','<=',$today)->count(),'/checks/due'],
            ['bounced','checks.view','شيكات مرتجعة',DB::table('checks')->where('status','bounced')->count(),'/checks/bounced'],
            ['approvals','approvals.view','طلبات اعتماد معلقة',DB::table('workflow_approvals')->where('status','pending')->count(),'/admin/workflow-approvals'],
        ];foreach($items as [$key,$permission,$title,$count,$path]){if($count<=0){DB::table('operational_alerts')->where('dedup_key',$key.':'.$today)->update(['expires_at'=>now()]);continue;}DB::table('operational_alerts')->updateOrInsert(['dedup_key'=>$key.':'.$today],['permission'=>$permission,'title'=>$title,'message'=>$count.' سجل يحتاج متابعة.','path'=>$path,'severity'=>$key==='bounced'?'danger':'warning','created_at'=>now(),'expires_at'=>now()->endOfDay()]);}
    }
}
