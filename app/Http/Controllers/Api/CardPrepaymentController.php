<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PrepaymentRequest;
use App\Http\Resources\CardPurchaseResource;
use App\Models\CardPurchase;
use App\Services\CardPurchasePlanner;
use App\Services\EarlyPaymentCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CardPrepaymentController extends Controller
{
    /**
     * Mostra quanto custaria antecipar as parcelas, sem pagar nada:
     * POST /api/purchases/{purchase}/prepayments/simulate
     */
    public function simulate(PrepaymentRequest $request, CardPurchase $purchase, EarlyPaymentCalculator $calculator): JsonResponse
    {
        Gate::authorize('view', $purchase);

        $quote = $this->quote($request, $purchase, $calculator);

        return response()->json($quote['summary']);
    }

    /**
     * Paga as parcelas escolhidas com o desconto de juros e libera o limite:
     * POST /api/purchases/{purchase}/prepayments
     */
    public function store(PrepaymentRequest $request, CardPurchase $purchase, EarlyPaymentCalculator $calculator): JsonResponse
    {
        Gate::authorize('view', $purchase);

        $quote = $this->quote($request, $purchase, $calculator);
        $paidAt = $this->paymentDate($request);

        DB::transaction(function () use ($quote, $paidAt) {
            foreach ($quote['models'] as $number => $installment) {
                $installment->forceFill([
                    'paid_at' => $paidAt,
                    'paid_amount' => CardPurchasePlanner::format($quote['paid_cents'][$number]),
                ])->save();
            }
        });

        return response()->json($quote['summary'] + [
            'purchase' => CardPurchaseResource::make($purchase->fresh(['category', 'installments'])),
        ]);
    }

    /**
     * @return array{summary:array,models:array,paid_cents:array}
     */
    private function quote(PrepaymentRequest $request, CardPurchase $purchase, EarlyPaymentCalculator $calculator): array
    {
        $numbers = array_values(array_unique($request->validated('numbers')));

        // Só parcelas desta compra que ainda não foram pagas.
        $installments = $purchase->installments()
            ->whereIn('number', $numbers)
            ->whereNull('paid_at')
            ->get()
            ->keyBy('number');

        $missing = array_values(array_diff($numbers, $installments->keys()->all()));
        if ($missing) {
            throw ValidationException::withMessages([
                'numbers' => ['Parcela(s) '.implode(', ', $missing).' não existe(m) ou já foi(ram) paga(s).'],
            ]);
        }

        $paymentDate = $this->paymentDate($request);
        $rate = (float) $purchase->interest_rate;

        $items = [];
        $paidCents = [];
        $originalTotal = 0;
        $payTotal = 0;

        foreach ($installments->sortKeys() as $number => $installment) {
            $result = $calculator->quote($installment, $rate, $paymentDate);

            $paidCents[$number] = $result['discounted_cents'];
            $originalTotal += $result['original_cents'];
            $payTotal += $result['discounted_cents'];

            $items[] = [
                'number' => $number,
                'due_date' => $installment->due_date->toDateString(),
                'days_early' => $result['days_early'],
                'original_amount' => CardPurchasePlanner::format($result['original_cents']),
                'amount_to_pay' => CardPurchasePlanner::format($result['discounted_cents']),
                'discount' => CardPurchasePlanner::format($result['original_cents'] - $result['discounted_cents']),
            ];
        }

        return [
            'summary' => [
                'payment_date' => $paymentDate->toDateString(),
                'interest_rate' => number_format($rate, 2, '.', ''),
                'installments' => $items,
                'total_original' => CardPurchasePlanner::format($originalTotal),
                'total_to_pay' => CardPurchasePlanner::format($payTotal),
                'total_savings' => CardPurchasePlanner::format($originalTotal - $payTotal),
            ],
            'models' => $installments->sortKeys()->all(),
            'paid_cents' => $paidCents,
        ];
    }

    private function paymentDate(PrepaymentRequest $request): Carbon
    {
        $date = $request->input('payment_date');

        return $date ? Carbon::createFromFormat('!Y-m-d', $date) : Carbon::today();
    }
}
