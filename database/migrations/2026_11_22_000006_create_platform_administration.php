<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 16)->default('member');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
        Schema::table('user_preferences', function (Blueprint $table): void {
            $table->string('locale', 20)->default('en');
            $table->string('date_format', 12)->default('Y-m-d');
            $table->string('number_format', 12)->default('en-US');
            $table->string('reporting_period', 10)->default('month');
            $table->boolean('budget_alerts')->default(true);
            $table->boolean('recurring_alerts')->default(true);
            $table->boolean('goal_alerts')->default(true);
            $table->boolean('email_alerts')->default(false);
            $table->string('avatar_path')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('target', 150);
            $table->ipAddress('ip')->nullable();
            $table->timestamp('created_at');
            $table->index(['created_at', 'id']);
        });
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('from_currency', 3)->references('code')->on('currencies');
            $table->char('to_currency', 3)->references('code')->on('currencies');
            $table->decimal('rate', 24, 12);
            $table->date('quoted_on');
            $table->string('source', 120);
            $table->timestamps();
            $table->unique(['from_currency', 'to_currency', 'quoted_on', 'source']);
        });
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('subject');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('email_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('alert_id')->nullable()->constrained('user_alerts')->nullOnDelete();
            $table->string('template', 40);
            $table->string('status', 12);
            $table->timestamp('created_at');
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['email_logs', 'email_templates', 'exchange_rates', 'audit_logs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('user_preferences', fn (Blueprint $table) => $table->dropColumn(['locale', 'date_format', 'number_format', 'reporting_period', 'budget_alerts', 'recurring_alerts', 'goal_alerts', 'email_alerts', 'avatar_path']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'suspended_at', 'deletion_requested_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
    }
};
