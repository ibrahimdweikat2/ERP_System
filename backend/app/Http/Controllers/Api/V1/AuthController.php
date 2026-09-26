<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Identity\Actions\Totp;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MFA_TTL = 300;

    private const MFA_MAX_ATTEMPTS = 5;

    public function login(LoginRequest $request, RecordAudit $audit, Totp $totp): JsonResponse|UserResource
    {
        $credentials = $request->validated();
        $remember = (bool) ($credentials['remember'] ?? false);
        $request->session()->forget('mfa_login');
        $user = User::where('email', $credentials['email'])->where('status', 'active')->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $audit->execute('auth.login_failed', 'user', null, after: ['email_hash' => hash('sha256', mb_strtolower($request->string('email')->toString()))]);
            throw ValidationException::withMessages(['email' => ['البريد الإلكتروني أو كلمة المرور غير صحيحة.']]);
        }
        // Password verified: MFA users complete a second step before any session login.
        if ($user->mfa_enabled_at || $user->mfa_required) {
            $pending = ['user_id' => $user->id, 'expires' => time() + self::MFA_TTL, 'attempts' => 0, 'remember' => $remember];
            $data = ['mfa' => 'verify'];
            if (! $user->mfa_enabled_at) {
                $pending['secret'] = $totp->secret();
                $data = ['mfa' => 'setup', 'secret' => $pending['secret'], 'uri' => $totp->uri($user, $pending['secret'])];
            }
            $request->session()->regenerate();
            $request->session()->put('mfa_login', $pending);
            $audit->execute('auth.mfa_challenge', 'user', $user->id, after: ['mode' => $data['mfa']]);

            return response()->json(['data' => $data]);
        }

        return $this->completeLogin($request, $user, $audit, $remember);
    }

    public function mfa(Request $request, RecordAudit $audit, Totp $totp): JsonResponse|UserResource
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:30']])['code'];
        $pending = $request->session()->get('mfa_login');
        $user = $pending && $pending['expires'] >= time() ? User::where('status', 'active')->find($pending['user_id']) : null;
        if (! $user) {
            $request->session()->forget('mfa_login');
            throw new BusinessException('MFA_SESSION_EXPIRED', 'انتهت مهلة التحقق. سجّل الدخول من جديد.', 422);
        }
        $recoveryCodes = null;
        if (isset($pending['secret'])) {
            $step = $totp->match($pending['secret'], trim($code));
            if ($step !== null) {
                $recoveryCodes = DB::transaction(function () use ($user, $pending, $step, $audit) {
                    $u = User::lockForUpdate()->findOrFail($user->id);
                    if ($u->mfa_enabled_at) {
                        throw new BusinessException('MFA_ALREADY_ENABLED', 'المصادقة الثنائية مفعّلة. سجّل الدخول من جديد.');
                    }
                    $codes = [];
                    for ($i = 0; $i < 8; $i++) {
                        $codes[] = strtoupper(bin2hex(random_bytes(5)));
                    }
                    $u->forceFill(['mfa_secret' => $pending['secret'], 'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes), 'mfa_enabled_at' => now(), 'mfa_last_step' => $step])->save();
                    $audit->execute('auth.mfa_enabled', 'user', $u->id, actorId: $u->id);

                    return $codes;
                });
            }
            $ok = $step !== null;
        } else {
            $ok = $totp->consume($user, $code);
        }
        if (! $ok) {
            $pending['attempts']++;
            $audit->execute('auth.mfa_failed', 'user', $user->id);
            if ($pending['attempts'] >= self::MFA_MAX_ATTEMPTS) {
                $request->session()->forget('mfa_login');
                throw new BusinessException('MFA_SESSION_EXPIRED', 'تجاوزت عدد المحاولات. سجّل الدخول من جديد.', 422);
            }
            $request->session()->put('mfa_login', $pending);
            throw new BusinessException('MFA_CODE_INVALID', 'الرمز غير صحيح. أدخل الرمز الحالي من تطبيق المصادقة.', 422);
        }
        $request->session()->forget('mfa_login');
        $resource = $this->completeLogin($request, $user, $audit, (bool) ($pending['remember'] ?? false));

        return $recoveryCodes ? response()->json(['data' => ['user' => $resource->resolve($request), 'recovery_codes' => $recoveryCodes]]) : $resource;
    }

    private function completeLogin(Request $request, User $user, RecordAudit $audit, bool $remember = false): UserResource
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);
        $audit->execute('auth.login', 'user', $request->user()->id);

        return new UserResource($request->user()->load('roles.permissions'));
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request, RecordAudit $audit): JsonResponse
    {
        $audit->execute('auth.logout', 'user', $request->user()->id);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink($data);

        return response()->json(['message' => 'إذا كان البريد مسجلاً، سيصلك رابط إعادة تعيين كلمة المرور.']);
    }

    public function reset(Request $request, RecordAudit $audit): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $status = Password::reset($data, function (User $user, string $password) use ($audit): void {
            DB::transaction(function () use ($user, $password, $audit): void {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $audit->execute('auth.password_reset', 'user', $user->id, actorId: $user->id);
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['الرابط غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً.']]);
        }

        return response()->json(['message' => 'تم تحديث كلمة المرور. يمكنك تسجيل الدخول.']);
    }
}
