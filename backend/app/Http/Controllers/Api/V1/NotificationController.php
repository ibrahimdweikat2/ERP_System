<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
class NotificationController extends Controller {
    public function index(Request $r):JsonResponse{return response()->json(DB::table('operational_alerts as a')->leftJoin('alert_reads as r',fn($j)=>$j->on('r.alert_id','=','a.id')->where('r.user_id',$r->user()->id))->when(!$r->user()->isOwner(),fn($q)=>$q->whereIn('a.permission',$r->user()->permissionNames()))->where(fn($q)=>$q->whereNull('a.expires_at')->orWhere('a.expires_at','>',now()))->select('a.*','r.read_at')->tap(fn($q)=>\App\Support\Search::apply($q,$r->query('search'),['a.title','a.message']))->orderByDesc('a.id')->paginate(\App\Support\PerPage::resolve(25)));}
    public function read(Request $r,int $id):JsonResponse{$a=DB::table('operational_alerts')->find($id);abort_unless($a&&$r->user()->hasPermission($a->permission),404);DB::table('alert_reads')->insertOrIgnore(['alert_id'=>$id,'user_id'=>$r->user()->id,'read_at'=>now()]);return response()->json(['message'=>'تم تعليم التنبيه كمقروء.']);}
}
