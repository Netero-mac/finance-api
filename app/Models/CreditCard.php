<?php

namespace App\Models;

use App\Services\CardPurchasePlanner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class CreditCard extends Model
{
    use HasFactory;

    // user_id fica fora do fillable: vem sempre do usuário autenticado.
    protected $fillable = [
        'name',
        'credit_limit',
        'closing_day',
        'due_day',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'closing_day' => 'integer',
            'due_day' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(CardPurchase::class);
    }

    public function installments(): HasManyThrough
    {
        return $this->hasManyThrough(CardInstallment::class, CardPurchase::class);
    }

    public function limitCents(): int
    {
        return CardPurchasePlanner::toCents($this->credit_limit);
    }

    /** Limite comprometido: soma das parcelas ainda em aberto. Parcelas pagas liberam o limite. */
    public function usedCents(): int
    {
        // "installments_sum_amount" vem pronto quando a listagem usa withSum().
        $sum = $this->getAttribute('installments_sum_amount')
            ?? $this->installments()
                ->whereNull('card_installments.paid_at')
                ->sum('card_installments.amount');

        return CardPurchasePlanner::toCents($sum);
    }

    public function availableCents(): int
    {
        return $this->limitCents() - $this->usedCents();
    }
}
