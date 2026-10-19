<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCardRequest;
use App\Http\Resources\CreditCardResource;
use App\Models\CreditCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CreditCardController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $cards = $request->user()
            ->creditCards()
            // Limite usado = parcelas em aberto, calculado sem uma consulta por cartão.
            ->withSum(['installments' => fn ($query) => $query->whereNull('card_installments.paid_at')], 'amount')
            ->orderBy('name')
            ->get();

        return CreditCardResource::collection($cards);
    }

    public function store(CreditCardRequest $request): JsonResponse
    {
        $card = $request->user()->creditCards()->create($request->validated());

        return CreditCardResource::make($card)
            ->response()
            ->setStatusCode(201);
    }

    public function show(CreditCard $card): CreditCardResource
    {
        Gate::authorize('view', $card);

        return CreditCardResource::make($card);
    }

    public function update(CreditCardRequest $request, CreditCard $card): CreditCardResource
    {
        Gate::authorize('update', $card);

        $card->update($request->validated());

        return CreditCardResource::make($card);
    }

    public function destroy(CreditCard $card): Response|JsonResponse
    {
        Gate::authorize('delete', $card);

        if ($card->purchases()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir um cartão que possui compras.',
            ], 409);
        }

        $card->delete();

        return response()->noContent();
    }
}
