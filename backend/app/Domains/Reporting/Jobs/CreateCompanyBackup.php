<?php
namespace App\Domains\Reporting\Jobs;
use App\Domains\Reporting\Actions\BackupCompanyData;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
// Runs in the requesting company's context (carried by the queue), so it sees and dumps only that company.
class CreateCompanyBackup implements ShouldQueue {
    use Dispatchable,InteractsWithQueue,Queueable;
    public int $tries=1;public int $timeout=1800;
    public function __construct(public int $backupId){$this->onQueue('maintenance');}
    public function handle(CompanyContext $context,BackupCompanyData $backup):void{$lock=Cache::lock('erp-company-backup-'.$context->requireCompanyId(),1900);if(!$lock->get())return;try{if(!$backup->execute($this->backupId))throw new \RuntimeException('Company backup failed; see company_backups.');}finally{$lock->release();}}
    public function failed(?\Throwable $e):void{DB::table('company_backups')->where('id',$this->backupId)->whereIn('status',['queued','processing'])->update(['status'=>'failed','error'=>'تعذر إنشاء النسخة الاحتياطية؛ راجع إعدادات الخادم وسجلاته.','completed_at'=>now()]);}
}
