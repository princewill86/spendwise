<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->string('name');
            $table->unsignedTinyInteger('decimal_places');
            $table->boolean('enabled')->default(true);
        });

        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->nullable()->references('code')->on('currencies');
            $table->string('theme', 8)->default('system');
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('hide_balances')->default(false);
            $table->timestamps();
        });

        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::create('content_pages', function (Blueprint $table): void {
            $table->string('slug')->primary();
            $table->string('title');
            $table->json('content');
            $table->string('meta_description', 200)->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('currencies');
    }
};
