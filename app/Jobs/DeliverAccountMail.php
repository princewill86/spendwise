<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeliverAccountMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public int $userId,
        public string $email,
        public string $kind,
        public ?string $token = null,
    ) {}

    public function handle(): void
    {
        $user = User::query()->where(['id' => $this->userId, 'email' => $this->email])->whereNull('suspended_at')->first();
        if (! $user || ($this->kind === 'verify' && $user->hasVerifiedEmail())) {
            return;
        }

        try {
            $user->notifyNow($this->kind === 'verify' ? new VerifyEmail : new ResetPassword($this->token));
        } catch (Throwable) {
            DB::table('email_logs')->insert([
                'user_id' => $this->userId, 'template' => $this->kind, 'status' => 'failed', 'created_at' => now(),
            ]);
            throw new \RuntimeException('The configured email transport failed.');
        }
    }
}
