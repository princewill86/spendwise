<?php

namespace App\Jobs;

use App\Domain\Platform\PlatformMail;
use App\Domain\Platform\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendFinanceAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $alertId) {}

    public function handle(Settings $settings, PlatformMail $mail): void
    {
        $alert = DB::table('user_alerts as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('user_preferences as p', 'p.user_id', '=', 'u.id')->where('a.id', $this->alertId)
            ->where('p.email_alerts', true)->whereNotNull('u.email_verified_at')->whereNull('u.suspended_at')
            ->first(['a.user_id', 'a.event_key', 'a.message', 'u.email']);
        if (! $alert || ! $settings->enabled('email_alerts', false)
            || DB::table('email_logs')->where(['alert_id' => $this->alertId, 'status' => 'sent'])->exists()) {
            return;
        }

        $key = explode(':', $alert->event_key, 2)[0];
        $template = DB::table('email_templates')->where('key', $key)->where('enabled', true)->first();
        if (! $template) {
            return;
        }

        try {
            $mail->configure($settings)->raw(
                str_replace('{message}', $alert->message, $template->body),
                fn ($message) => $message->to($alert->email)->subject($template->subject)
            );
            $this->log($alert->user_id, $key, 'sent');
        } catch (Throwable) {
            $this->log($alert->user_id, $key, 'failed');
            // Keep provider errors out of the failed-jobs table because they can contain credentials.
            throw new \RuntimeException('The configured email transport failed.');
        }
    }

    private function log(int $userId, string $key, string $status): void
    {
        DB::table('email_logs')->insert([
            'alert_id' => $this->alertId, 'user_id' => $userId, 'template' => $key, 'status' => $status, 'created_at' => now(),
        ]);
    }
}
