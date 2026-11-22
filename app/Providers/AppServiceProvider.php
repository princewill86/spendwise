<?php

namespace App\Providers;

use App\Domain\Finance\OpenExchangeRates;
use App\Domain\Finance\RateFeed;
use App\Domain\Finance\RateProvider;
use App\Domain\Finance\RecordedRates;
use App\Domain\Platform\PlatformMail;
use App\Domain\Platform\Settings;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(Settings::class);
        $this->app->bind(RateProvider::class, RecordedRates::class);
        $this->app->bind(RateFeed::class, OpenExchangeRates::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('administer-platform', fn (User $user): bool => $user->role === 'admin' && ! $user->suspended_at);

        VerifyEmail::toMailUsing(fn (User $user, string $url) => $this->accountMessage('verify', 'Verify your email', $url));
        ResetPassword::toMailUsing(fn (User $user, string $token) => $this->accountMessage('reset', 'Reset your password', route('password.reset', ['token' => $token, 'email' => $user->email])));
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if ($event->channel !== 'mail' || ! ($event->notification instanceof VerifyEmail || $event->notification instanceof ResetPassword)) {
                return;
            }
            DB::table('email_logs')->insert([
                'user_id' => $event->notifiable->id,
                'template' => $event->notification instanceof VerifyEmail ? 'verify' : 'reset',
                'status' => 'sent', 'created_at' => now(),
            ]);
        });
    }

    private function accountMessage(string $key, string $action, string $url): MailMessage
    {
        $settings = app(Settings::class);
        app(PlatformMail::class)->configure($settings);
        $template = DB::table('email_templates')->where('key', $key)->first();
        $message = (new MailMessage)->subject($template?->subject ?? $action)
            ->line($template?->body ?? 'Use the secure link below to continue.')->action($action, $url);
        $configuration = $settings->get('mail', []);
        if ($configuration) {
            $message->mailer('spendwise');
            if (! empty($configuration['reply_to'])) {
                $message->replyTo($configuration['reply_to']);
            }
        }

        return $message;
    }
}
