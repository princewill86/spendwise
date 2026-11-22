<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'name', 'type', 'currency', 'opening_balance', 'description', 'archived_at'])]
class FinancialAccount extends Model
{
    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:4', 'archived_at' => 'datetime'];
    }
}
