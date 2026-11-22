<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'category_id', 'type', 'description', 'occurred_on', 'notes', 'payment_method'])]
class Transaction extends Model
{
    use SoftDeletes;
}
