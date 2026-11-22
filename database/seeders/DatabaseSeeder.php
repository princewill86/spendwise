<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('currencies')->insertOrIgnore([
            ['code' => 'CAD', 'name' => 'Canadian dollar', 'decimal_places' => 2],
            ['code' => 'USD', 'name' => 'US dollar', 'decimal_places' => 2],
            ['code' => 'NGN', 'name' => 'Nigerian naira', 'decimal_places' => 2],
            ['code' => 'GBP', 'name' => 'Pound sterling', 'decimal_places' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'decimal_places' => 2],
            ['code' => 'JPY', 'name' => 'Japanese yen', 'decimal_places' => 0],
        ]);

        $templates = [
            ['verify', 'Verify your email address', 'Verify your email address to access your Spendwise workspace.'],
            ['reset', 'Reset your password', 'Follow this link to reset your Spendwise password. If you did not request it, you can ignore this message.'],
            ['budget', 'Spendwise budget update', '{message}'],
            ['recurring', 'Spendwise recurring activity', '{message}'],
            ['goal', 'Spendwise savings update', '{message}'],
            ['security', 'Spendwise security update', '{message}'],
        ];
        DB::table('email_templates')->insertOrIgnore(array_map(fn (array $entry) => [
            'key' => $entry[0], 'subject' => $entry[1], 'body' => $entry[2], 'created_at' => now(), 'updated_at' => now(),
        ], $templates));

        $pages = json_decode(file_get_contents(database_path('seeders/content.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($pages as $slug => $page) {
            DB::table('content_pages')->insertOrIgnore([
                'slug' => $slug,
                'title' => $page['title'],
                'content' => json_encode($page['content'], JSON_THROW_ON_ERROR),
                'meta_description' => $page['description'],
                'published' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
