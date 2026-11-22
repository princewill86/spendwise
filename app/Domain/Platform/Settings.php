<?php

namespace App\Domain\Platform;

use Illuminate\Support\Facades\DB;

final class Settings
{
    private array $loaded = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, $this->loaded)) {
            $value = DB::table('platform_settings')->where('key', $key)->value('value');
            $this->loaded[$key] = $value === null ? null : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        return $this->loaded[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        DB::table('platform_settings')->upsert([
            ['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()],
        ], ['key'], ['value', 'updated_at']);
        $this->loaded[$key] = $value;
    }

    public function enabled(string $key, bool $default = true): bool
    {
        return (bool) ($this->get('features', [])[$key] ?? $default);
    }
}
