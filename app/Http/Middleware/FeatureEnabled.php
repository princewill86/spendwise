<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(app(Settings::class)->enabled($feature), 404);

        return $next($request);
    }
}
