<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('financial_accounts');
            $table->foreignId('category_id')->nullable()->constrained();
            $table->string('description', 160);
            $table->string('type', 12);
            $table->decimal('amount', 20, 4);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_on');
            $table->string('interval_unit', 8);
            $table->unsignedSmallInteger('interval_count');
            $table->string('status', 10)->default('active');
            $table->timestamps();
            $table->index(['status', 'next_on', 'id']);
        });
        Schema::create('recurring_occurrences', function (Blueprint $table): void {
            $table->foreignId('recurring_rule_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_on');
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->unique(['recurring_rule_id', 'scheduled_on']);
        });
        Schema::create('user_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 150);
            $table->string('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_alerts');
        Schema::dropIfExists('recurring_occurrences');
        Schema::dropIfExists('recurring_rules');
    }
};
