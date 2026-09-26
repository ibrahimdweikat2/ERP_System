<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Reporting\Actions\ReportEngine;
use App\Domains\Reporting\Actions\ReconcileLedgers;
use App\Domains\Reporting\Jobs\GenerateReportExport;
use App\Domains\Audit\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Support\IdempotentRequest;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
class ReportController extends Controller {
    private function filters(Request $r):array{return $r->validate(['from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],'account_id'=>['nullable','integer','exists:accounts,id'],'location_id'=>['nullable','integer','exists:stock_locations,id'],'status'=>['nullable','string','max:30'],'per_page'=>['nullable','integer','between:1,100']]);}
    public function catalog(Request $r):JsonResponse{$rows=[];foreach(ReportEngine::REPORTS as $key=>[$label,$permission])if($r->user()->hasPermission($permission))$rows[]=['key'=>$key,'label'=>$label,'permission'=>$permission];return response()->json(['data'=>$rows]);}
    public function report(Request $r,string $report):JsonResponse{app(ReportEngine::class)->authorize($report,$r->user());$f=$this->filters($r);if($report==='reconciliation')return response()->json(['data'=>app(ReconcileLedgers::class)->execute(),'snapshot_date'=>today()->toDateString()]);return response()->json([...app(ReportEngine::class)->query($report,$f)->paginate(\App\Support\PerPage::resolve(50))->toArray(),'summary'=>app(ReportEngine::class)->financialSummary($report,$f)]);}
    public function exports(Request $r,IdempotentRequest $idem):JsonResponse{
        if($r->isMethod('get'))return response()->json(DB::table('report_exports')->where('requested_by',$r->user()->id)->select('id','report','status','row_count','error','expires_at','created_at','completed_at')->tap(fn($q)=>\App\Support\Search::apply($q,$r->query('search'),['report','status']))->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
        $d=$r->validate(['report'=>['required','string','max:60'],'filters'=>['sometimes','array:from,to,account_id,location_id,status'],'filters.from'=>['nullable','date_format:Y-m-d'],'filters.to'=>['nullable','date_format:Y-m-d','after_or_equal:filters.from'],'filters.account_id'=>['nullable','integer','exists:accounts,id'],'filters.location_id'=>['nullable','integer','exists:stock_locations,id'],'filters.status'=>['nullable','string','max:30']]);app(ReportEngine::class)->authorize($d['report'],$r->user());
        $result=$idem->execute($r->user()->id,'reports.export',(string)$r->header('Idempotency-Key'),$d,function()use($d,$r){$id=DB::table('report_exports')->insertGetId(['report'=>$d['report'],'filters'=>json_encode($d['filters']??[],JSON_THROW_ON_ERROR),'requested_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);GenerateReportExport::dispatch($id)->afterCommit();app(RecordAudit::class)->execute('reports.export_requested','report_export',$id,null,$d,$r->user()->id);return ['id'=>$id];});return response()->json(['data'=>DB::table('report_exports')->where('id',$result['id'])->first(['id','report','status','row_count'])],202);
    }
    public function download(Request $r,int $id):\Symfony\Component\HttpFoundation\StreamedResponse{
        $e=DB::table('report_exports')->find($id);abort_unless($e&&$e->requested_by===$r->user()->id,404);app(ReportEngine::class)->authorize($e->report,$r->user());abort_unless($e->status==='completed'&&$e->expires_at>now()->toDateTimeString()&&Storage::disk('documents')->exists($e->stored_path),404);app(RecordAudit::class)->execute('reports.export_downloaded','report_export',$id);return Storage::disk('documents')->download($e->stored_path,$e->report.'-'.$id.'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function dashboard(Request $r):JsonResponse{
        $tiles=[];$u=$r->user();$today=today()->toDateString();$add=function($label,$amount,$path,$unit='base')use(&$tiles){$tiles[]=['label'=>$label,'amount'=>(string)$amount,'path'=>$path,'unit'=>$unit];};
        if($u->hasPermission('sales.view')){$add('مبيعات اليوم',DB::table('sales_invoices')->where('status','posted')->where('document_date',$today)->sum('base_total'),'/sales/invoices');$add('مبيعات الشهر',DB::table('sales_invoices')->where('status','posted')->whereBetween('document_date',[date('Y-m-01'),$today])->sum('base_total'),'/reports/sales');}
        if($u->hasPermission('reports.financial')){$add('ذمم العملاء',DB::table('customer_ledger_entries')->sum('base_amount'),'/reports/receivables');$add('ذمم الموردين',DB::table('supplier_ledger_entries')->sum('base_amount'),'/reports/payables');}
        if($u->hasPermission('inventory.view_cost'))$add('قيمة المخزون',DB::table('inventory_balances')->sum('inventory_value'),'/reports/inventory-valuation');
        if($u->hasPermission('installments.view'))$add('أقساط مستحقة اليوم',DB::table('installment_schedule')->where('superseded',false)->where('due_date',$today)->whereRaw('amount>paid_amount+adjustment_amount')->count(),'/installments/due','count');
        if($u->hasPermission('checks.view'))$add('شيكات مرتجعة',DB::table('checks')->where('status','bounced')->count(),'/checks/bounced','count');
        return response()->json(['data'=>['tiles'=>$tiles,'as_of'=>$today]]);
    }
}
