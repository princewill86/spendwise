<?php

namespace App\Domain\Platform;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

final class PlatformMail
{
    public function configure(Settings $settings): Mailer
    {
        $mail = $settings->get('mail', []);
        if (! $mail) {
            return Mail::mailer();
        }

        config([
            'mail.from.address' => $mail['from_address'],
            'mail.from.name' => $mail['from_name'],
            'mail.reply_to.address' => $mail['reply_to'] ?? null,
            'mail.mailers.spendwise' => match ($mail['driver']) {
                'smtp' => [
                    'transport' => 'smtp', 'host' => $mail['host'], 'port' => $mail['port'], 'scheme' => $mail['scheme'],
                    'username' => $mail['username'] ?? null,
                    'password' => isset($mail['encrypted_password']) ? Crypt::decryptString($mail['encrypted_password']) : null,
                    'timeout' => 10, 'require_tls' => $mail['scheme'] === 'smtp',
                ],
                'sendmail' => ['transport' => 'sendmail', 'path' => config('mail.mailers.sendmail.path')],
                default => ['transport' => 'log', 'channel' => config('mail.mailers.log.channel')],
            },
        ]);
        Mail::purge('spendwise');

        return Mail::mailer('spendwise');
    }
}
