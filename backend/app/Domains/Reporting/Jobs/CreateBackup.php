<?php
namespace App\Domains\Reporting\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
class CreateBackup implements ShouldQueue {
    use Dispatchable,InteractsWithQueue,Queueable;
    public int $tries=1;public int $timeout=1800;
    public function __construct(){$this->onQueue('maintenance');}
    public function handle():void{$lock=Cache::lock('erp-backup-running',1900);if(!$lock->get())return;try{if(Artisan::call('erp:backup')!==0)throw new \RuntimeException('Backup command failed; see backup_runs.');}finally{$lock->release();}}
}
