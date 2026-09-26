<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Reporting\Jobs\CreateBackup;
use App\Support\IdempotentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OperationsHealthController extends Controller {
    public function status(){return response()->json(['data'=>['backups'=>DB::table('backup_runs')->orderByDesc('id')->limit(20)->get(['id','status','checksum','error','started_at','completed_at']),'queues'=>DB::table('jobs')->selectRaw('queue,COUNT(*) AS pending,MIN(created_at) AS oldest_pending_at')->groupBy('queue')->get(),'failed'=>DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get(['id','uuid','connection','queue','failed_at']),'backup_schedule'=>'02:00','export_retention_days'=>7]]);}
    public function backup(Request $r,IdempotentRequest $idem){$result=$idem->execute($r->user()->id,'operations.backup',(string)$r->header('Idempotency-Key'),[],function()use($r){CreateBackup::dispatch()->afterCommit();app(RecordAudit::class)->execute('operations.backup_requested','system',null,null,[],$r->user()->id);return ['queued'=>true];});return response()->json(['data'=>$result],202);}
}
