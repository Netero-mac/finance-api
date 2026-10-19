<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_purchase_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->string('invoice_month', 7); // AAAA-MM do vencimento da fatura
            $table->timestamps();

            $table->unique(['card_purchase_id', 'number']);
            $table->index('invoice_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_installments');
    }
};
