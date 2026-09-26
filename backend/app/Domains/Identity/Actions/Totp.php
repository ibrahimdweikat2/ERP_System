<?php
namespace App\Domains\Identity\Actions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
class Totp {
    public function secret():string{$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split(random_bytes(20)) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5) as $chunk)$out.=$alphabet[bindec(str_pad($chunk,5,'0'))];return $out;}
    public function match(string $secret,string $code,?int $last=null):?int{
        if(!preg_match('/^\d{6}$/',$code))return null;$bits='';$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';foreach(str_split($secret) as $c){$pos=strpos($alphabet,$c);if($pos===false)return null;$bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT);}$key='';foreach(str_split($bits,8) as $chunk)if(strlen($chunk)===8)$key.=chr(bindec($chunk));
        $now=intdiv(time(),30);foreach([-1,0,1] as $offset){$step=$now+$offset;if($last!==null&&$step<=$last)continue;$h=hash_hmac('sha1',pack('N2',0,$step),$key,true);$o=ord($h[19])&15;$n=unpack('N',substr($h,$o,4))[1]&0x7fffffff;$expected=str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);if(hash_equals($expected,$code))return $step;}return null;
    }
    public function uri(User $user,string $secret):string{return 'otpauth://totp/'.rawurlencode('Daftar:'.$user->email).'?secret='.$secret.'&issuer=Daftar&algorithm=SHA1&digits=6&period=30';}
    public function consume(User $user,string $code):bool{
        return DB::transaction(function()use($user,$code){$u=User::lockForUpdate()->findOrFail($user->id);$step=$this->match($u->mfa_secret??'',trim($code),$u->mfa_last_step);if($step!==null){$u->forceFill(['mfa_last_step'=>$step])->save();return true;}$hashes=$u->mfa_recovery_codes??[];foreach($hashes as $i=>$hash)if(Hash::check(mb_strtoupper(trim($code)),$hash)){unset($hashes[$i]);$u->forceFill(['mfa_recovery_codes'=>array_values($hashes)])->save();return true;}return false;},5);
    }
}
