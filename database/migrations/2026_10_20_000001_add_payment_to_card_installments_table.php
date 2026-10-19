<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_installments', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable();           // quando a parcela foi paga
            $table->decimal('paid_amount', 10, 2)->nullable();  // quanto foi pago (com desconto, se antecipada)
        });
    }

    public function down(): void
    {
        Schema::table('card_installments', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'paid_amount']);
        });
    }
};
