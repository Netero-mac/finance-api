<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('credit_limit', 10, 2);
            $table->unsignedTinyInteger('closing_day'); // dia de fechamento da fatura (1 a 28)
            $table->unsignedTinyInteger('due_day');     // dia de vencimento da fatura (1 a 28)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
