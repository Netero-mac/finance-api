<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SummaryController extends Controller
{
    /**
     * Resumo mensal: GET /api/summary?month=2026-10 (sem o parâmetro, usa o mês atual).
     */
    public function __invoke(Request $request): JsonResponse
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

        $incomeCents = $this->toCents($totals['income'] ?? 0);
        $expenseCents = $this->toCents($totals['expense'] ?? 0);

        // Total por categoria, do maior para o menor.
        $byCategory = $monthQuery()
            ->selectRaw('category_id, type, SUM(amount) as total')
            ->groupBy('category_id', 'type')
            ->with('category')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id,
                'category' => $row->category->name,
                'type' => $row->type->value,
                'total_cents' => $this->toCents($row->total),
            ])
            ->sortByDesc('total_cents')
            ->values()
            ->map(fn ($item) => [
                'category_id' => $item['category_id'],
                'category' => $item['category'],
                'type' => $item['type'],
                'total' => $this->format($item['total_cents']),
            ]);

        return response()->json([
            'month' => $month,
            'income' => $this->format($incomeCents),
            'expense' => $this->format($expenseCents),
            'balance' => $this->format($incomeCents - $expenseCents),
            'by_category' => $byCategory,
        ]);
    }

    // Somamos em centavos (inteiros) para evitar erros de arredondamento com decimais.
    private function toCents(float|int|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
