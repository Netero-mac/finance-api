<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardInstallment extends Model
{
    use HasFactory;

    // paid_at e paid_amount só são definidos pelo fluxo de pagamento/antecipação.
    protected $fillable = [
        'number',
        'amount',
        'due_date',
        'invoice_month',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CardPurchase::class, 'card_purchase_id');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }
}
