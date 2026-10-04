<?php

namespace App\Providers;

use App\Support\Tenancy\CompanyAwareUserProvider;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\TenantMySqlConnection;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Every MySQL connection compiles queries through the company-filtering grammar.
        Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new TenantMySqlConnection($pdo, $database, $prefix, $config));
        // Scoped: queue workers reset it between jobs, so no job inherits another's company.
        $this->app->scoped(CompanyContext::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(20)->by('login-ip:'.$r->ip()),
            Limit::perMinute(5)->by('login-account:'.hash('sha256', mb_strtolower((string) $r->input('email'))).'|'.$r->ip()),
        ]);
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(3)->by('reset:'.$r->ip()));
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));

        Auth::provider('company-aware', fn ($app, array $config) => new CompanyAwareUserProvider($app['hash'], $config['model']));
        // Migrations create and backfill tables for all companies at once.
        Event::listen(MigrationsStarted::class, fn () => $this->app->make(CompanyContext::class)->enterBypass());
        Event::listen(MigrationsEnded::class, fn () => $this->app->make(CompanyContext::class)->leaveBypass());
        // A queued job runs as the company that dispatched it.
        Context::hydrated(fn (ContextRepository $context) => $this->app->make(CompanyContext::class)->restore($context->getHidden('tenancy')));
    }
}
