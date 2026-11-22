<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PlatformRuntime
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(Settings::class);
        if ($request->is('user/two-factor-authentication') && $request->isMethod('POST')) {
            abort_unless($settings->enabled('mfa'), 403);
        }

        if ($request->isMethod('POST') && $request->is('forgot-password', 'reset-password', 'user/confirm-password', 'user/confirmed-two-factor-authentication')) {
            $key = 'spendwise:auth:'.$request->path().':'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, 8)) {
                abort(429, 'Too many attempts. Try again shortly.', ['Retry-After' => RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, 60);
        }
        $security = $settings->get('security', []);
        if (isset($security['session_lifetime'])) {
            config(['session.lifetime' => $security['session_lifetime']]);
        }

        return $next($request);
    }
}
