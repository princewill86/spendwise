<?php

namespace App\Policies;

use App\Models\FinancialAccount;
use App\Models\User;

class FinancialAccountPolicy
{
    public function update(User $user, FinancialAccount $account): bool
    {
        return $user->id === $account->user_id;
    }
}
