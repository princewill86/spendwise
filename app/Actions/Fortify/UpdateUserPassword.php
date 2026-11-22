<?php

namespace App\Actions\Fortify;

use App\Domain\Finance\Alerts;
use App\Domain\Platform\Settings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    public function update(User $user, array $input): void
    {
        $minimum = app(Settings::class)->get('security', [])['password_min'] ?? 12;
        $data = Validator::make($input, [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min($minimum), 'max:72', 'different:current_password'],
        ])->validateWithBag('updatePassword');
        DB::transaction(function () use ($user, $data): void {
            $user->forceFill(['password' => $data['password'], 'remember_token' => null])->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))
                    ->where('user_id', $user->id)->where('id', '!=', request()->session()->getId())->delete();
            }
        });
        app(Alerts::class)->send($user->id, 'security:password:'.now()->getTimestamp(), 'Your Spendwise password was changed.');
    }
}
