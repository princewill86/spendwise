<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Audit;
use App\Domain\Platform\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BrandingController extends Controller
{
    public static function sharingUrl(Settings $settings): ?string
    {
        $path = $settings->get('branding', [])['sharing']['path'] ?? null;

        return $path ? route('branding', ['kind' => 'sharing', 'v' => substr(hash('sha256', $path), 0, 12)]) : ($settings->get('seo', [])['image'] ?? null);
    }

    public function image(Settings $settings, string $kind): StreamedResponse
    {
        $asset = $settings->get('branding', [])[$kind] ?? null;
        abort_unless($asset && Storage::disk('local')->exists($asset['path']), 404);

        return Storage::disk('local')->response($asset['path'], null, ['Content-Type' => $asset['mime'], 'Cache-Control' => 'public, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function update(Request $request, Settings $settings, string $kind): RedirectResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimetypes:image/png,image/jpeg,image/webp', 'max:1024', 'dimensions:max_width=1500,max_height=1500']]);
        $file = $request->file('image');
        $path = $file->store('branding', 'local');
        abort_unless($path, 500);

        try {
            $this->save($request, $settings, $kind, ['path' => $path, 'mime' => $file->getMimeType()]);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return back()->with('status', 'Branding image saved.');
    }

    public function remove(Request $request, Settings $settings, string $kind): RedirectResponse
    {
        $this->save($request, $settings, $kind, null);

        return back()->with('status', 'Branding image removed.');
    }

    private function save(Request $request, Settings $settings, string $kind, ?array $asset): void
    {
        DB::transaction(function () use ($request, $settings, $kind, $asset): void {
            DB::table('platform_settings')->insertOrIgnore(['key' => 'branding', 'value' => '{}']);
            $row = DB::table('platform_settings')->where('key', 'branding')->lockForUpdate()->first();
            $branding = json_decode($row->value, true, flags: JSON_THROW_ON_ERROR);
            $old = $branding[$kind]['path'] ?? null;
            $branding[$kind] = $asset;
            $settings->put('branding', $branding);
            if ($kind === 'sharing') {
                $seo = $settings->get('seo', []);
                unset($seo['image']);
                $settings->put('seo', $seo);
            }
            Audit::record($request, 'branding.updated', $kind);
            if ($old) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($old));
            }
        });
    }
}
