<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardPurchase extends Model
{
    use HasFactory;

    // credit_card_id é definido pela relação com o cartão.
    protected $fillable = [
        'category_id',
        'description',
        'total_amount',
        'installments_count',
        'interest_rate',
        'total_with_interest',
        'purchase_date',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'installments_count' => 'integer',
            'interest_rate' => 'decimal:2',
            'total_with_interest' => 'decimal:2',
            'purchase_date' => 'date',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class, 'credit_card_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(CardInstallment::class)->orderBy('number');
    }
}
