<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = parent::handle($request, $next);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($request->routeIs('home', 'content', 'custom-content') && ! (app(Settings::class)->get('seo', [])['indexing'] ?? true)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if (! $request->routeIs('home', 'content', 'custom-content') && ! $request->is('sitemap.xml', 'robots.txt', 'branding/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'user' => fn () => $request->user()?->only('name', 'role'),
            'platform' => fn () => [
                'name' => app(Settings::class)->get('general', [])['name'] ?? 'Spendwise',
                'theme' => app(Settings::class)->get('general', [])['theme'] ?? 'system',
                'registration' => app(Settings::class)->enabled('registration'),
                'logo' => ! empty(app(Settings::class)->get('branding', [])['logo']) ? route('branding', ['kind' => 'logo']) : null,
                'favicon' => ! empty(app(Settings::class)->get('branding', [])['favicon']) ? route('branding', ['kind' => 'favicon']) : null,
                'navigation' => app(Settings::class)->get('navigation', []),
                'personalTheme' => $request->user() ? DB::table('user_preferences')->where('user_id', $request->user()->id)->value('theme') : null,
                'features' => collect(['goals', 'recurring', 'reports', 'imports'])->mapWithKeys(fn (string $key) => [$key => app(Settings::class)->enabled($key)])->all(),
            ],
            'status' => fn () => $request->session()->get('status'),
        ];
    }
}
