<?php

namespace App\Actions\Fortify;

use App\Domain\Platform\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    public function create(array $input): User
    {
        $settings = app(Settings::class);
        abort_unless($settings->enabled('registration'), 403);
        $minimum = $settings->get('security', [])['password_min'] ?? 12;
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:254', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min($minimum), 'max:72'],
            'terms' => ['accepted'],
        ])->validate();

        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
    }
}
