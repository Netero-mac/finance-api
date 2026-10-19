<?php

namespace App\Services;

use App\Models\CreditCard;
use Carbon\Carbon;

/**
 * Monta o plano de uma compra parcelada: valor das parcelas (com ou sem juros)
 * e a fatura em que cada parcela vai cair. Tudo em centavos (inteiros).
 */
class CardPurchasePlanner
{
    /**
     * @param  float|int|string  $total        valor da compra, sem juros
     * @param  float|int|string  $monthlyRate  juros ao mês, em % (0 = sem juros)
     * @return array{principal_cents:int,total_cents:int,installments:array<int,array{number:int,amount_cents:int,due_date:Carbon,invoice_month:string}>}
     */
    public function plan(CreditCard $card, float|int|string $total, int $count, float|int|string $monthlyRate, Carbon $purchaseDate): array
    {
        $principalCents = self::toCents($total);
        $amounts = $this->installmentAmounts($principalCents, $count, ((float) $monthlyRate) / 100);
        $firstDue = $this->firstDueDate($card, $purchaseDate);

        $installments = [];
        foreach ($amounts as $index => $cents) {
            $due = $firstDue->copy()->addMonthsNoOverflow($index);

            $installments[] = [
                'number' => $index + 1,
                'amount_cents' => $cents,
                'due_date' => $due,
                'invoice_month' => $due->format('Y-m'),
            ];
        }

        return [
            'principal_cents' => $principalCents,
            'total_cents' => array_sum($amounts),
            'installments' => $installments,
        ];
    }

    /**
     * Valor de cada parcela, em centavos.
     *
     * Sem juros: divide o valor e distribui os centavos que sobram nas primeiras parcelas,
     * para a soma fechar exatamente.
     * Com juros: Tabela Price, parcelas fixas: PMT = PV * i / (1 - (1 + i)^-n).
     *
     * @return int[]
     */
    private function installmentAmounts(int $principalCents, int $count, float $rate): array
    {
        if ($rate <= 0) {
            $base = intdiv($principalCents, $count);
            $remainder = $principalCents % $count;

            return array_map(
                fn (int $index) => $base + ($index < $remainder ? 1 : 0),
                range(0, $count - 1)
            );
        }

        $payment = $principalCents * $rate / (1 - (1 + $rate) ** (-$count));

        return array_fill(0, $count, (int) round($payment));
    }

    /**
     * Vencimento da primeira parcela.
     *
     * Compras feitas até o dia de fechamento (inclusive) entram na fatura que fecha neste mês;
     * depois disso, entram na fatura que fecha no mês seguinte. Se o vencimento é depois do
     * fechamento no calendário (ex.: fecha dia 5, vence dia 15), a fatura vence no mesmo mês
     * em que fecha; senão (ex.: fecha dia 25, vence dia 5), vence no mês seguinte.
     */
    public function firstDueDate(CreditCard $card, Carbon $purchaseDate): Carbon
    {
        $closingMonth = $purchaseDate->copy()->startOfMonth();

        if ($purchaseDate->day > $card->closing_day) {
            $closingMonth->addMonthNoOverflow();
        }

        $dueMonth = $card->due_day > $card->closing_day
            ? $closingMonth
            : $closingMonth->copy()->addMonthNoOverflow();

        return $dueMonth->copy()->day($card->due_day);
    }

    public static function toCents(float|int|string|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
