<?php

namespace App\Services;

use App\Models\CardInstallment;
use Carbon\Carbon;

/**
 * Calcula o valor a pagar ao antecipar uma parcela.
 *
 * O Código de Defesa do Consumidor (art. 52, § 2º) garante a redução proporcional
 * dos juros na liquidação antecipada. Aqui isso é feito trazendo a parcela a valor
 * presente pela taxa da própria compra:
 *
 *     valor a pagar = parcela / (1 + i) ^ (dias de antecipação / 30)
 *
 * Compras sem juros não têm o que descontar. Pagar depois do vencimento não é
 * antecipação, então não há desconto.
 */
class EarlyPaymentCalculator
{
    /**
     * @return array{original_cents:int,discounted_cents:int,days_early:int}
     */
    public function quote(CardInstallment $installment, float $monthlyRatePercent, Carbon $paymentDate): array
    {
        $originalCents = CardPurchasePlanner::toCents($installment->amount);

        $seconds = $installment->due_date->copy()->startOfDay()->getTimestamp()
            - $paymentDate->copy()->startOfDay()->getTimestamp();
        $daysEarly = max(0, intdiv($seconds, 86400));

        $rate = $monthlyRatePercent / 100;
        $discountedCents = $originalCents;

        if ($rate > 0 && $daysEarly > 0) {
            $presentValue = $originalCents / ((1 + $rate) ** ($daysEarly / 30));
            $discountedCents = min($originalCents, (int) round($presentValue));
        }

        return [
            'original_cents' => $originalCents,
            'discounted_cents' => $discountedCents,
            'days_early' => $daysEarly,
        ];
    }
}
