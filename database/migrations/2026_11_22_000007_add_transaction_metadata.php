<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', fn (Blueprint $table) => $table->string('payment_method', 48)->nullable());
        Schema::create('transaction_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('filename', 180);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_attachments');
        Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('payment_method'));
    }
};
