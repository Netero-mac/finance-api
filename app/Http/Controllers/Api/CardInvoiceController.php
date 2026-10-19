<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditCard;
use App\Services\CardPurchasePlanner;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CardInvoiceController extends Controller
{
    /**
     * Fatura de um cartão: GET /api/cards/{card}/invoice?month=2026-11
     * O mês é o do vencimento da fatura. Sem o parâmetro, usa o mês atual.
     *
     * "total" soma só as parcelas em aberto; parcelas já pagas (por exemplo,
     * antecipadas) aparecem na lista com o valor efetivamente pago.
     */
    public function __invoke(Request $request, CreditCard $card): JsonResponse
    {
        Gate::authorize('view', $card);

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = $data['month'] ?? now()->format('Y-m');

        $due = Carbon::createFromFormat('!Y-m', $month)->day($card->due_day);
        $closing = $card->due_day > $card->closing_day
            ? $due->copy()->day($card->closing_day)
            : $due->copy()->subMonthsNoOverflow(1)->day($card->closing_day);

        $installments = $card->installments()
            ->where('card_installments.invoice_month', $month)
            ->with('purchase.category')
            ->orderBy('card_installments.due_date')
            ->orderBy('card_installments.id')
            ->get();

        $openCents = $installments
            ->whereNull('paid_at')
            ->sum(fn ($item) => CardPurchasePlanner::toCents($item->amount));

        $paidCents = $installments
            ->whereNotNull('paid_at')
            ->sum(fn ($item) => CardPurchasePlanner::toCents($item->paid_amount));

        return response()->json([
            'card' => ['id' => $card->id, 'name' => $card->name],
            'month' => $month,
            'closing_date' => $closing->toDateString(),
            'due_date' => $due->toDateString(),
            'total' => CardPurchasePlanner::format($openCents),
            'paid_total' => CardPurchasePlanner::format($paidCents),
            'items' => $installments->map(fn ($item) => [
                'installment_id' => $item->id,
                'purchase_id' => $item->card_purchase_id,
                'description' => $item->purchase->description,
                'category' => $item->purchase->category?->name,
                'number' => $item->number,
                'count' => $item->purchase->installments_count,
                'amount' => $item->amount,
                'status' => $item->isPaid() ? 'paid' : 'open',
                'paid_amount' => $item->paid_amount,
                'purchase_date' => $item->purchase->purchase_date->toDateString(),
            ])->values(),
        ]);
    }
}
