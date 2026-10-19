<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardInstallment extends Model
{
    use HasFactory;

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
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(CardPurchase::class, 'card_purchase_id');
    }
}
