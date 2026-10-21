<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\CardPurchasePlanner;
use App\Services\CardSpending;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SummaryController extends Controller
{
    /**
     * Resumo mensal: GET /api/summary?month=2026-10 (sem o parâmetro, usa o mês atual).
     *
     * As despesas incluem as parcelas do cartão de crédito (veja CardSpending).
     */
    public function __invoke(Request $request, CardSpending $cardSpending): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = $data['month'] ?? now()->format('Y-m');

        // O "!" zera os campos não informados, então o dia vira 01.
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $monthQuery = fn () => $request->user()
            ->transactions()
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString());

        // Total por tipo (receita e despesa) numa única consulta agregada.
        $totals = $monthQuery()
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->type->value => $row->total]);

        $cardRows = $cardSpending->byCategory($request->user(), $start, $end);
        $cardCents = array_sum(array_column($cardRows, 'cents'));

        $incomeCents = CardPurchasePlanner::toCents($totals['income'] ?? 0);
        $expenseCents = CardPurchasePlanner::toCents($totals['expense'] ?? 0) + $cardCents;

        // Linhas por categoria, primeiro com as transações...
        $rows = [];
        $monthQuery()
            ->selectRaw('category_id, type, SUM(amount) as total')
            ->groupBy('category_id', 'type')
            ->with('category')
            ->get()
            ->each(function ($row) use (&$rows) {
                $rows[$row->type->value.':'.$row->category_id] = [
                    'category_id' => $row->category_id,
                    'category' => $row->category->name,
                    'type' => $row->type->value,
                    'total_cents' => CardPurchasePlanner::toCents($row->total),
                    'card_cents' => 0,
                ];
            });

        // ...e depois somando as parcelas do cartão como despesa.
        $names = Category::whereIn('id', array_filter(array_column($cardRows, 'category_id')))->pluck('name', 'id');

        foreach ($cardRows as $card) {
            $key = 'expense:'.($card['category_id'] ?? '');

            $rows[$key] ??= [
                'category_id' => $card['category_id'],
                'category' => $card['category_id'] ? ($names[$card['category_id']] ?? 'Sem categoria') : 'Sem categoria',
                'type' => 'expense',
                'total_cents' => 0,
                'card_cents' => 0,
            ];

            $rows[$key]['total_cents'] += $card['cents'];
            $rows[$key]['card_cents'] += $card['cents'];
        }

        $byCategory = collect($rows)
            ->sortByDesc('total_cents')
            ->values()
            ->map(fn (array $item) => [
                'category_id' => $item['category_id'],
                'category' => $item['category'],
                'type' => $item['type'],
                'total' => CardPurchasePlanner::format($item['total_cents']),
                'card' => CardPurchasePlanner::format($item['card_cents']),
            ]);

        return response()->json([
            'month' => $month,
            'income' => CardPurchasePlanner::format($incomeCents),
            'expense' => CardPurchasePlanner::format($expenseCents),
            'card_expense' => CardPurchasePlanner::format($cardCents),
            'balance' => CardPurchasePlanner::format($incomeCents - $expenseCents),
            'by_category' => $byCategory,
        ]);
    }
}
