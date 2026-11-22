<?php

namespace App\Actions\Fortify;

use App\Domain\Platform\Settings;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    public function reset(User $user, array $input): void
    {
        $minimum = app(Settings::class)->get('security', [])['password_min'] ?? 12;
        Validator::make($input, [
            'password' => ['required', 'confirmed', Password::min($minimum), 'max:72'],
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill(['password' => $input['password'], 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $user->id)->delete();
            }
        });
    }
}
