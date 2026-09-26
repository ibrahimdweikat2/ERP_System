<?php
namespace App\Http\Controllers\Api\V1;
use App\Domains\Identity\Actions\Totp;
use App\Domains\Audit\Actions\RecordAudit;
use App\Models\User;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
class MfaController extends Controller {
    public function status(Request $r):JsonResponse{return response()->json(['data'=>['enabled'=>(bool)$r->user()->mfa_enabled_at,'recovery_codes_remaining'=>count($r->user()->mfa_recovery_codes??[])]]);}
    public function setup(Request $r):JsonResponse{$d=$r->validate(['password'=>['required','string']]);if(!Hash::check($d['password'],$r->user()->password))throw new BusinessException('PASSWORD_INVALID','كلمة المرور الحالية غير صحيحة.');if($r->user()->mfa_enabled_at)throw new BusinessException('MFA_ALREADY_ENABLED','المصادقة الثنائية مفعّلة.');$secret=app(Totp::class)->secret();$r->session()->put('mfa_setup',['secret'=>$secret,'expires'=>time()+600]);return response()->json(['data'=>['secret'=>$secret,'uri'=>app(Totp::class)->uri($r->user(),$secret)]]);}
    public function enable(Request $r):JsonResponse{
        $d=$r->validate(['code'=>['required','string','size:6']]);$pending=$r->session()->get('mfa_setup');if(!$pending||$pending['expires']<time()||($step=app(Totp::class)->match($pending['secret'],$d['code']))===null)throw new BusinessException('MFA_CODE_INVALID','رمز التفعيل غير صحيح أو انتهت جلسة الإعداد.');
        $codes=[];for($i=0;$i<8;$i++)$codes[]=strtoupper(bin2hex(random_bytes(5)));
        DB::transaction(function()use($r,$pending,$codes,$step){$u=User::lockForUpdate()->findOrFail($r->user()->id);if($u->mfa_enabled_at)throw new BusinessException('MFA_ALREADY_ENABLED','المصادقة الثنائية مفعّلة.');$u->forceFill(['mfa_secret'=>$pending['secret'],'mfa_recovery_codes'=>array_map(fn($c)=>Hash::make($c),$codes),'mfa_enabled_at'=>now(),'mfa_last_step'=>$step])->save();DB::table('sessions')->where('user_id',$u->id)->where('id','!=',$r->session()->getId())->delete();app(RecordAudit::class)->execute('auth.mfa_enabled','user',$u->id,null,[], $u->id);});$r->session()->forget('mfa_setup');return response()->json(['data'=>['recovery_codes'=>$codes]]);
    }
    public function disable(Request $r):JsonResponse{$d=$r->validate(['password'=>['required','string'],'code'=>['required','string','max:30']]);if(!Hash::check($d['password'],$r->user()->password)||!app(Totp::class)->consume($r->user(),$d['code']))throw new BusinessException('MFA_VERIFICATION_FAILED','راجع كلمة المرور ورمز المصادقة.');$r->user()->forceFill(['mfa_secret'=>null,'mfa_recovery_codes'=>null,'mfa_enabled_at'=>null,'mfa_last_step'=>null])->save();app(RecordAudit::class)->execute('auth.mfa_disabled','user',$r->user()->id);return response()->json(['message'=>'تم إيقاف المصادقة الثنائية.']);}
}
