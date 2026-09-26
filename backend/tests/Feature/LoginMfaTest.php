<?php

namespace Tests\Feature;

use App\Domains\Identity\Actions\Totp;
use App\Domains\Identity\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginMfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(array $attributes = []): User
    {
        $u = User::factory()->create(['password' => Hash::make('SecurePass123!'), ...$attributes]);
        $u->roles()->attach(Role::where('name', 'owner')->firstOrFail());

        return $u;
    }

    private function code(string $secret, int $offset = 0): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $key .= chr(bindec($chunk));
            }
        }
        $h = hash_hmac('sha1', pack('N2', 0, intdiv(time(), 30) + $offset), $key, true);
        $o = ord($h[19]) & 15;

        return str_pad((string) ((unpack('N', substr($h, $o, 4))[1] & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function test_login_without_mfa_is_single_step(): void
    {
        $u = $this->user();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePass123!'])->assertOk()->assertJsonPath('data.id', $u->id);
        $this->assertAuthenticatedAs($u);
    }

    public function test_required_mfa_enrolls_with_qr_on_first_login(): void
    {
        $u = $this->user(['mfa_required' => true]);
        $r = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePass123!'])->assertOk()->assertJsonPath('data.mfa', 'setup');
        $this->assertGuest();
        $secret = $r->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', $r->json('data.uri'));
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login/mfa', ['code' => '000000'])->assertUnprocessable()->assertJsonPath('code', 'MFA_CODE_INVALID');
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($secret)])->assertOk()->assertJsonCount(8, 'data.recovery_codes')->assertJsonPath('data.user.id', $u->id);
        $this->assertAuthenticatedAs($u);
        $this->assertNotNull($u->fresh()->mfa_enabled_at);
    }

    public function test_enrolled_user_verifies_without_qr_and_recovery_code_works_once(): void
    {
        $totp = app(Totp::class);
        $secret = $totp->secret();
        $u = $this->user(['mfa_required' => true]);
        $u->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => now(), 'mfa_recovery_codes' => [Hash::make('ABCDE12345')]])->save();
        $r = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePass123!'])->assertOk()->assertJsonPath('data.mfa', 'verify');
        $this->assertNull($r->json('data.secret'));
        $this->assertNull($r->json('data.uri'));
        $this->postJson('/api/v1/auth/login/mfa', ['code' => 'abcde12345'])->assertOk()->assertJsonPath('data.id', $u->id);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePass123!'])->assertJsonPath('data.mfa', 'verify');
        $this->postJson('/api/v1/auth/login/mfa', ['code' => 'ABCDE12345'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login/mfa', ['code' => $this->code($secret)])->assertOk();
        $this->assertAuthenticatedAs($u);
    }

    public function test_mfa_step_requires_password_step_and_limits_attempts(): void
    {
        $this->postJson('/api/v1/auth/login/mfa', ['code' => '123456'])->assertUnprocessable()->assertJsonPath('code', 'MFA_SESSION_EXPIRED');
        $u = $this->user(['mfa_required' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePass123!'])->assertJsonPath('data.mfa', 'setup');
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login/mfa', ['code' => '000000'])->assertJsonPath('code', 'MFA_CODE_INVALID');
        }
        $this->postJson('/api/v1/auth/login/mfa', ['code' => '000000'])->assertJsonPath('code', 'MFA_SESSION_EXPIRED');
        $this->assertGuest();
    }

    public function test_remember_me_sets_recaller_cookie_only_when_requested(): void
    {
        $u = $this->user();
        $recaller = auth()->guard('web')->getRecallerName();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePass123!'])->assertOk()->assertCookieMissing($recaller);
        auth()->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'SecurePass123!', 'remember' => true])->assertOk()->assertCookie($recaller);
        $this->assertNotNull($u->fresh()->remember_token);
    }
}
