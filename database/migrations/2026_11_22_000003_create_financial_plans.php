<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained();
            $table->char('currency', 3)->references('code')->on('currencies');
            $table->string('name', 100);
            $table->string('period', 8);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('amount', 20, 4);
            $table->unsignedTinyInteger('threshold')->default(80);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'starts_on', 'ends_on']);
        });

        Schema::create('savings_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->references('code')->on('currencies');
            $table->string('name', 100);
            $table->decimal('amount', 20, 4);
            $table->date('target_date')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 10)->default('active');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('savings_contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('savings_goal_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 20, 4);
            $table->string('note', 240)->nullable();
            $table->timestamp('created_at');
            $table->index(['savings_goal_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_contributions');
        Schema::dropIfExists('savings_goals');
        Schema::dropIfExists('budgets');
    }
};
