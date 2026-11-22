<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/dashboard',
    'middleware' => ['web'],
    'views' => true,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor', 'verification' => '6,1', 'password-reset' => '6,1'],
    'features' => [
        Features::registration(),
        Features::updatePasswords(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
    ],
];
