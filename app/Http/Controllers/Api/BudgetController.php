<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\BudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Models\User;
use App\Services\CardPurchasePlanner;
use App\Services\CardSpending;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class BudgetController extends Controller
{
    public function __construct(private CardSpending $cardSpending)
    {
    }

    /**
     * Lista os orçamentos com o andamento do mês: GET /api/budgets?month=2026-10
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $month = $data['month'] ?? now()->format('Y-m');

        $budgets = $request->user()
            ->budgets()
            ->with('category')
            ->get()
            ->sortBy(fn (Budget $budget) => $budget->category->name)
            ->values();

        $this->withSpent($request->user(), $budgets, $month);

        return BudgetResource::collection($budgets)->additional(['month' => $month]);
    }

    public function store(BudgetRequest $request): JsonResponse
    {
        $budget = $request->user()->budgets()->create($request->validated());
        $budget->load('category');

        $this->withSpent($request->user(), collect([$budget]), now()->format('Y-m'));

        return BudgetResource::make($budget)
            ->response()
            ->setStatusCode(201);
    }

    public function update(BudgetRequest $request, Budget $budget): BudgetResource
    {
        Gate::authorize('update', $budget);

        $budget->update($request->validated());
        $budget->load('category');

        $this->withSpent($request->user(), collect([$budget]), now()->format('Y-m'));

        return BudgetResource::make($budget);
    }

    public function destroy(Budget $budget): Response
    {
        Gate::authorize('delete', $budget);

        $budget->delete();

        return response()->noContent();
    }

    /**
     * Soma as despesas do mês por categoria e anexa o valor gasto a cada orçamento.
     * O gasto inclui as transações e as parcelas do cartão de crédito do mês.
     */
    private function withSpent(User $user, Collection $budgets, string $month): void
    {
        // O "!" zera os campos não informados, então o dia vira 01.
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $spent = $user->transactions()
            ->where('type', TransactionType::Expense->value)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->category_id => $row->total]);

        $cardByCategory = [];
        foreach ($this->cardSpending->byCategory($user, $start, $end) as $row) {
            if ($row['category_id'] !== null) {
                $cardByCategory[$row['category_id']] = $row['cents'];
            }
        }

        foreach ($budgets as $budget) {
            $cents = CardPurchasePlanner::toCents($spent[$budget->category_id] ?? 0)
                + ($cardByCategory[$budget->category_id] ?? 0);

            $budget->setAttribute('spent_amount', $cents / 100);
        }
    }
}
