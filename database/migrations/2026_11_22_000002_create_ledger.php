<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->references('code')->on('currencies');
            $table->string('name', 100);
            $table->string('type', 20);
            $table->decimal('opening_balance', 20, 4);
            $table->text('description')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'archived_at']);
            $table->unique(['id', 'user_id', 'currency']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 12);
            $table->string('color', 7)->nullable();
            $table->string('icon', 32)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'type', 'name']);
            $table->unique(['id', 'user_id', 'type']);
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('type', 12);
            $table->string('description', 160);
            $table->date('occurred_on');
            $table->text('notes')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'occurred_on', 'id']);
            $table->foreign(['category_id', 'user_id', 'type'])->references(['id', 'user_id', 'type'])->on('categories');
        });

        Schema::create('ledger_postings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('user_id');
            $table->char('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->foreign(['transaction_id', 'user_id'])->references(['id', 'user_id'])->on('transactions')->cascadeOnDelete();
            $table->foreign(['account_id', 'user_id', 'currency'])->references(['id', 'user_id', 'currency'])->on('financial_accounts');
            $table->unique(['transaction_id', 'account_id']);
            $table->index(['user_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_postings');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('financial_accounts');
    }
};
