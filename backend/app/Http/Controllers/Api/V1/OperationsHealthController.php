<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Reporting\Jobs\CreateBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OperationsHealthController extends Controller {
    public function status(){return response()->json(['data'=>['backups'=>DB::table('backup_runs')->orderByDesc('id')->limit(20)->get(['id','status','checksum','error','started_at','completed_at']),'queues'=>DB::table('jobs')->selectRaw('queue,COUNT(*) AS pending,MIN(created_at) AS oldest_pending_at')->groupBy('queue')->get(),'failed'=>DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get(['id','uuid','connection','queue','failed_at']),'backup_schedule'=>'02:00','export_retention_days'=>7]]);}
    // Platform only: the backup covers every company. A repeated request is harmless; CreateBackup holds a lock.
    public function backup(Request $r){CreateBackup::dispatch()->afterCommit();app(RecordAudit::class)->execute('operations.backup_requested','system',null,null,[],$r->user()->id);return response()->json(['data'=>['queued'=>true]],202);}
}
