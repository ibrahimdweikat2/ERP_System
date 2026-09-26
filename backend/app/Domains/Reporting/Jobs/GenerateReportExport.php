<?php
namespace App\Domains\Reporting\Jobs;
use App\Domains\Reporting\Actions\ReportEngine;
use App\Domains\Reporting\Actions\ReconcileLedgers;
use App\Domains\Audit\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
class GenerateReportExport implements ShouldQueue {
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=2;public int $timeout=300;public int $backoff=30;
    public function __construct(public int $exportId){$this->onQueue('exports');}
    public function handle():void{
        $row=DB::table('report_exports')->find($this->exportId);if(!$row||$row->status==='completed')return;
        $user=User::findOrFail($row->requested_by);app(ReportEngine::class)->authorize($row->report,$user);
        DB::table('report_exports')->where('id',$row->id)->update(['status'=>'processing','started_at'=>now(),'error'=>null,'updated_at'=>now()]);
        $path='exports/report-'.$row->id.'.csv';$temp=fopen('php://temp/maxmemory:2097152','w+b');fwrite($temp,"\xEF\xBB\xBF");$count=0;
        $rows=$row->report==='reconciliation'?app(ReconcileLedgers::class)->execute():app(ReportEngine::class)->query($row->report,json_decode($row->filters,true,512,JSON_THROW_ON_ERROR))->lazy(1000);
        foreach($rows as $item){$values=(array)$item;if(!$count)fputcsv($temp,array_keys($values),',','"','');$cells=array_map(function($v){$value=(string)($v??'');if(preg_match('/^[=+@\t\r]/u',$value)|| (str_starts_with($value,'-')&&!preg_match('/^-\d+(\.\d+)?$/',$value)))$value="'".$value;return $value;},array_values($values));fputcsv($temp,$cells,',','"','');$count++;}
        rewind($temp);Storage::disk('documents')->put($path,$temp);fclose($temp);
        DB::table('report_exports')->where('id',$row->id)->update(['status'=>'completed','stored_path'=>$path,'row_count'=>$count,'completed_at'=>now(),'expires_at'=>now()->addDays(7),'updated_at'=>now()]);app(RecordAudit::class)->execute('reports.export_completed','report_export',$row->id,null,['report'=>$row->report,'rows'=>$count],$row->requested_by);
    }
    public function failed(\Throwable $e):void{DB::table('report_exports')->where('id',$this->exportId)->update(['status'=>'failed','error'=>'تعذر تجهيز التقرير. راجع حالة العامل ثم أعد الطلب.','updated_at'=>now()]);}
}
