<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(20)->by('login-ip:'.$r->ip()),
            Limit::perMinute(5)->by('login-account:'.hash('sha256', mb_strtolower((string) $r->input('email'))).'|'.$r->ip()),
        ]);
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(3)->by('reset:'.$r->ip()));
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
    }
}
