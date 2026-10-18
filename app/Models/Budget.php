<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    use HasFactory;

    /** A partir de quantos % do limite o orçamento entra em alerta. */
    public const WARNING_THRESHOLD = 80;

    // user_id fica fora do fillable: vem sempre do usuário autenticado.
    protected $fillable = [
        'category_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Calcula o andamento do orçamento a partir do valor gasto no mês.
     * Trabalha em centavos (inteiros) para evitar erros de arredondamento.
     *
     * Status:
     *  - ok:       abaixo de 80% do limite
     *  - warning:  a partir de 80% e até 100% do limite
     *  - exceeded: gastou mais que o limite
     */
    public function progress(float|int|string $spent): array
    {
        $limitCents = (int) round(((float) $this->amount) * 100);
        $spentCents = (int) round(((float) $spent) * 100);

        $percent = $limitCents > 0 ? round($spentCents / $limitCents * 100, 1) : 0.0;

        $status = match (true) {
            $spentCents > $limitCents => 'exceeded',
            $percent >= self::WARNING_THRESHOLD => 'warning',
            default => 'ok',
        };

        return [
            'spent' => number_format($spentCents / 100, 2, '.', ''),
            'remaining' => number_format(($limitCents - $spentCents) / 100, 2, '.', ''),
            'percent' => $percent,
            'status' => $status,
        ];
    }
}
