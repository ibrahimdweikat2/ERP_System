<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SecurityHeaders {
    public function handle(Request $r,Closure $next):\Symfony\Component\HttpFoundation\Response{$response=$next($r);$response->headers->set('X-Content-Type-Options','nosniff');$response->headers->set('X-Frame-Options','DENY');$response->headers->set('Referrer-Policy','same-origin');$response->headers->set('Permissions-Policy','camera=(), microphone=(), geolocation=()');if($r->is('api/*'))$response->headers->set('Cache-Control','private, no-store');if($r->isSecure())$response->headers->set('Strict-Transport-Security','max-age=31536000');return $response;}
}
