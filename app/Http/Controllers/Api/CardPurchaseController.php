<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurchaseRequest;
use App\Http\Requests\PurchaseSimulationRequest;
use App\Http\Resources\CardPurchaseResource;
use App\Models\CardPurchase;
use App\Models\CreditCard;
use App\Services\CardPurchasePlanner;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CardPurchaseController extends Controller
{
    public function index(CreditCard $card): AnonymousResourceCollection
    {
        Gate::authorize('view', $card);

        $purchases = $card->purchases()
            ->with(['category', 'installments'])
            ->latest('purchase_date')
            ->latest('id')
            ->get();

        return CardPurchaseResource::collection($purchases);
    }

    /**
     * Simula uma compra parcelada sem salvar nada: POST /api/cards/{card}/simulate
     */
    public function simulate(PurchaseSimulationRequest $request, CreditCard $card, CardPurchasePlanner $planner): JsonResponse
    {
        Gate::authorize('view', $card);

        $plan = $this->buildPlan($request, $card, $planner);
        $availableCents = $card->availableCents();
        $first = $plan['installments'][0];

        return response()->json([
            'installments_count' => count($plan['installments']),
            'interest_rate' => number_format((float) ($request->input('interest_rate') ?? 0), 2, '.', ''),
            'principal' => CardPurchasePlanner::format($plan['principal_cents']),
            'installment_amount' => CardPurchasePlanner::format($first['amount_cents']),
            'total_with_interest' => CardPurchasePlanner::format($plan['total_cents']),
            'interest_total' => CardPurchasePlanner::format($plan['total_cents'] - $plan['principal_cents']),
            'first_due_date' => $first['due_date']->toDateString(),
            'available_limit' => CardPurchasePlanner::format($availableCents),
            'fits_limit' => $plan['total_cents'] <= $availableCents,
            'installments' => array_map(fn (array $item) => [
                'number' => $item['number'],
                'amount' => CardPurchasePlanner::format($item['amount_cents']),
                'due_date' => $item['due_date']->toDateString(),
                'invoice_month' => $item['invoice_month'],
            ], $plan['installments']),
        ]);
    }

    public function store(PurchaseRequest $request, CreditCard $card, CardPurchasePlanner $planner): JsonResponse
    {
        Gate::authorize('view', $card);

        $plan = $this->buildPlan($request, $card, $planner);
        $availableCents = $card->availableCents();

        if ($plan['total_cents'] > $availableCents) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Limite insuficiente no cartão. Disponível: R$ '.CardPurchasePlanner::format($availableCents).'.',
                ],
            ]);
        }

        $purchase = DB::transaction(function () use ($request, $card, $plan) {
            $purchase = $card->purchases()->create([
                'category_id' => $request->input('category_id'),
                'description' => $request->input('description'),
                'total_amount' => CardPurchasePlanner::format($plan['principal_cents']),
                'installments_count' => count($plan['installments']),
                'interest_rate' => (float) ($request->input('interest_rate') ?? 0),
                'total_with_interest' => CardPurchasePlanner::format($plan['total_cents']),
                'purchase_date' => $this->purchaseDate($request)->toDateString(),
            ]);

            $purchase->installments()->createMany(array_map(fn (array $item) => [
                'number' => $item['number'],
                'amount' => CardPurchasePlanner::format($item['amount_cents']),
                'due_date' => $item['due_date']->toDateString(),
                'invoice_month' => $item['invoice_month'],
            ], $plan['installments']));

            return $purchase;
        });

        $purchase->load(['category', 'installments']);

        return CardPurchaseResource::make($purchase)
            ->response()
            ->setStatusCode(201);
    }

    public function show(CardPurchase $purchase): CardPurchaseResource
    {
        Gate::authorize('view', $purchase);

        return CardPurchaseResource::make($purchase->load(['category', 'installments']));
    }

    public function destroy(CardPurchase $purchase): Response|JsonResponse
    {
        Gate::authorize('delete', $purchase);

        // Compra com parcelas já pagas tem histórico financeiro: não pode ser apagada.
        if ($purchase->installments()->whereNotNull('paid_at')->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir uma compra que já tem parcelas pagas.',
            ], 409);
        }

        // As parcelas são removidas em cascata, e o limite é liberado.
        $purchase->delete();

        return response()->noContent();
    }

    private function buildPlan(PurchaseSimulationRequest $request, CreditCard $card, CardPurchasePlanner $planner): array
    {
        return $planner->plan(
            $card,
            $request->input('total_amount'),
            (int) $request->input('installments'),
            $request->input('interest_rate') ?? 0,
            $this->purchaseDate($request),
        );
    }

    private function purchaseDate(PurchaseSimulationRequest $request): Carbon
    {
        $date = $request->input('purchase_date');

        return $date ? Carbon::createFromFormat('!Y-m-d', $date) : Carbon::today();
    }
}
