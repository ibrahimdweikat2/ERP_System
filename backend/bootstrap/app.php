<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantUser;
use App\Http\Middleware\RequireActiveUser;
use App\Http\Middleware\RequirePermission;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use App\Support\BusinessException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->alias(['active' => RequireActiveUser::class, 'permission' => RequirePermission::class, 'tenant' => EnsureTenantUser::class, 'platform' => EnsurePlatformAdmin::class]);
        // The company context must exist before route-model binding resolves {product} etc.,
        // so these run straight after authentication instead of after SubstituteBindings.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, RequireActiveUser::class);
        $middleware->appendToPriorityList(RequireActiveUser::class, EnsureTenantUser::class);
        $middleware->appendToPriorityList(EnsureTenantUser::class, EnsurePlatformAdmin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (BusinessException $e, Request $r) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'errors' => (object) []], $e->status);
        });
        $exceptions->render(function (ValidationException $e, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['message' => 'يرجى مراجعة البيانات المدخلة.', 'code' => 'VALIDATION_FAILED', 'errors' => $e->errors()], 422);
            }
        });
        $exceptions->respond(function (Response $response) {
            if (request()->is('api/*') && $response->getStatusCode() >= 400) {
                $body = json_decode($response->getContent(), true);
                if (is_array($body) && ! isset($body['code'])) {
                    $code = match ($response->getStatusCode()) {
                        401 => 'UNAUTHENTICATED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 419 => 'SESSION_EXPIRED', 429 => 'RATE_LIMITED', default => 'REQUEST_FAILED'
                    };

                    if ($response->getStatusCode() === 429) {
                        $body['message'] = 'محاولات كثيرة خلال وقت قصير. انتظر قليلاً ثم أعد المحاولة.';
                    }

                    return response()->json([...$body, 'code' => $code, 'errors' => (object) []], $response->getStatusCode(), $response->headers->all());
                }
            }

            return $response;
        });
    })->create();
