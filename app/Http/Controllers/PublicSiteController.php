<?php

namespace App\Http\Controllers;

use App\Domain\Platform\PlatformMail;
use App\Domain\Platform\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PublicSiteController extends Controller
{
    public const BUILT_IN = ['features', 'about', 'how-it-works', 'security', 'faq', 'contact', 'privacy', 'terms', 'cookies', 'data-deletion', 'acceptable-use'];

    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public static function pageUrl(string $slug): string
    {
        return $slug === 'home' ? route('home') : route(in_array($slug, self::BUILT_IN, true) ? 'content' : 'custom-content', ['slug' => $slug]);
    }

    public function home(Settings $settings): Response
    {
        return Inertia::render('Home', $this->published('home', $settings));
    }

    public function page(Request $request, Settings $settings, string $slug): Response
    {
        abort_if($request->routeIs('custom-content') && in_array($slug, ['home', ...self::BUILT_IN], true), 404);

        return Inertia::render('Content', $this->published($slug, $settings));
    }

    public function contact(Request $request, Settings $settings, PlatformMail $mail): RedirectResponse
    {
        $contact = $settings->get('general', [])['contact'] ?? null;
        abort_unless($contact, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:254'],
            'message' => ['required', 'string', 'min:8', 'max:3000'],
        ]);
        try {
            $mail->configure($settings)->raw(
                $data['name']."\n".$data['email']."\n\n".$data['message'],
                fn ($message) => $message->to($contact)->replyTo($data['email'])->subject('Spendwise contact request')
            );
        } catch (Throwable) {
            throw ValidationException::withMessages(['message' => 'The configured mail transport could not process the message. Try again later.']);
        }

        return back()->with('status', 'Your message was submitted.');
    }

    public function robots(Settings $settings): \Illuminate\Http\Response
    {
        $seo = $settings->get('seo', []);
        $paths = ['accounts', 'transactions', 'categories', 'dashboard', 'admin', 'login', 'register', 'email/', 'forgot-password', 'reset-password', 'budgets', 'goals', 'recurring', 'calendar', 'notifications', 'imports', 'reports', 'exports', 'settings', 'user/', 'two-factor-challenge'];
        $rules = ($seo['indexing'] ?? true) ? array_map(fn (string $path) => "Disallow: /{$path}", $paths) : ['Disallow: /'];
        if (($seo['indexing'] ?? true) && ($seo['sitemap'] ?? true)) {
            $rules[] = 'Sitemap: '.url('/sitemap.xml');
        }

        return response("User-agent: *\n".implode("\n", $rules)."\n")->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function sitemap(Settings $settings): \Illuminate\Http\Response
    {
        $seo = $settings->get('seo', []);
        abort_unless(($seo['indexing'] ?? true) && ($seo['sitemap'] ?? true), 404);
        $home = $seo['canonical'] ?? $settings->get('general', [])['site_url'] ?? route('home');
        $pages = DB::table('content_pages')->where('published', true)->orderBy('slug')->pluck('slug')->map(fn (string $slug) => $slug === 'home' ? $home : self::pageUrl($slug));

        return response()->view('sitemap', ['pages' => $pages])->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function published(string $slug, Settings $settings): array
    {
        abort_unless($settings->get('general', [])['public_site'] ?? true, 404);
        $page = DB::table('content_pages')->where('slug', $slug)->where('published', true)->first();
        abort_unless($page, 404);
        $seo = $settings->get('seo', []);
        $contactConfigured = $slug === 'contact' && ! empty($settings->get('general', [])['contact']);

        return [
            'title' => $page->title,
            'seoTitle' => $slug === 'home' ? ($seo['title'] ?? $page->title) : $page->title,
            'content' => json_decode($page->content, true, flags: JSON_THROW_ON_ERROR),
            'description' => $page->meta_description ?? $seo['description'] ?? null,
            'canonical' => $slug === 'home' ? ($seo['canonical'] ?? $settings->get('general', [])['site_url'] ?? route('home')) : self::pageUrl($slug),
            'image' => BrandingController::sharingUrl($settings),
            'contactConfigured' => $contactConfigured,
            'slug' => $slug,
        ];
    }
}
