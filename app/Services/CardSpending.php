<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Quanto do cartão de crédito vira despesa em um período, por categoria.
 *
 * Regime de caixa: a parcela conta no mês em que o dinheiro sai da conta.
 *  - parcela em aberto: no mês do vencimento, pelo valor da parcela;
 *  - parcela antecipada: no mês do pagamento, pelo valor efetivamente pago
 *    (já com o desconto de juros).
 */
class CardSpending
{
    /**
     * @return array<int, array{category_id:?int,cents:int}>
     */
    public function byCategory(User $user, Carbon $start, Carbon $end): array
    {
        $effectiveDate = 'COALESCE(DATE(card_installments.paid_at), DATE(card_installments.due_date))';
        $effectiveAmount = 'COALESCE(card_installments.paid_amount, card_installments.amount)';

        $rows = DB::table('card_installments')
            ->join('card_purchases', 'card_purchases.id', '=', 'card_installments.card_purchase_id')
            ->join('credit_cards', 'credit_cards.id', '=', 'card_purchases.credit_card_id')
            ->where('credit_cards.user_id', $user->id)
            ->whereRaw("{$effectiveDate} >= ?", [$start->toDateString()])
            ->whereRaw("{$effectiveDate} <= ?", [$end->toDateString()])
            ->selectRaw("card_purchases.category_id as category_id, SUM({$effectiveAmount}) as total")
            ->groupBy('card_purchases.category_id')
            ->get();

        return $rows->map(fn ($row) => [
            'category_id' => $row->category_id === null ? null : (int) $row->category_id,
            'cents' => CardPurchasePlanner::toCents($row->total),
        ])->all();
    }
}
