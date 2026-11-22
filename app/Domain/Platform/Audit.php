<?php

namespace App\Domain\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(Request $request, string $action, string $target): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $request->user()->id, 'action' => $action,
            'target' => $target, 'ip' => $request->ip(), 'created_at' => now(),
        ]);
    }
}
