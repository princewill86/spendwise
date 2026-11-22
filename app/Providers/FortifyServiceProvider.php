<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Domain\Platform\Settings;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', mb_strtolower($request->string('email')->toString()))->first();
            if (! $user || ! Hash::check($request->string('password')->toString(), $user->password) || $user->suspended_at) {
                return null;
            }

            return $user;
        });

        Fortify::loginView(fn () => Inertia::render('Auth', ['mode' => 'login']));
        Fortify::registerView(function () {
            abort_unless(app(Settings::class)->enabled('registration'), 404);

            return Inertia::render('Auth', ['mode' => 'register']);
        });
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth', ['mode' => 'forgot']));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth', [
            'mode' => 'reset',
            'email' => $request->string('email')->toString(),
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('Auth', ['mode' => 'verify']));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth', ['mode' => 'two-factor']));
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by((string) $request->session()->get('login.id').'|'.$request->ip()),
            Limit::perMinute(25)->by($request->ip()),
        ]);
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth', ['mode' => 'confirm']));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(app(Settings::class)->get('security', [])['login_limit'] ?? 5)->by(Str::lower($request->string('email')->toString()).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
