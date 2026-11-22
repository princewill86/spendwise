<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PlatformMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = app(Settings::class)->get('general', [])['maintenance'] ?? false;
        if ($maintenance && ! $request->user()?->can('administer-platform') && ! $request->is('login', 'logout', 'two-factor-challenge')) {
            return response('Spendwise is temporarily unavailable for maintenance.', 503)
                ->header('Retry-After', '300')->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
