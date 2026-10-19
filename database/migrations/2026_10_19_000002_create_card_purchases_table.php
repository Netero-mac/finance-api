<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('total_amount', 10, 2);          // valor da compra, sem juros
            $table->unsignedSmallInteger('installments_count');
            $table->decimal('interest_rate', 5, 2)->default(0); // juros ao mês, em %
            $table->decimal('total_with_interest', 10, 2);   // soma das parcelas
            $table->date('purchase_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_purchases');
    }
};
