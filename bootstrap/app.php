<?php

use App\Http\Middleware\ActiveMember;
use App\Http\Middleware\FeatureEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PlatformMaintenance;
use App\Http\Middleware\PlatformRuntime;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [PlatformRuntime::class], append: [ActiveMember::class, PlatformMaintenance::class, HandleInertiaRequests::class]);
        $middleware->alias(['feature' => FeatureEnabled::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['credential', 'current_password', 'recovery_code']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
