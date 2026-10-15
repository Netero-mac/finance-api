<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    /**
     * Filtros: ?from=2026-10-01&to=2026-10-31&category_id=1&type=expense&per_page=15
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $toRules = ['nullable', 'date_format:Y-m-d'];

        if ($request->filled('from')) {
            $toRules[] = 'after_or_equal:from';
        }

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => $toRules,
            'category_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $transactions = $request->user()
            ->transactions()
            ->with('category')
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('date', '<=', $to))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->latest('date')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }

    public function store(TransactionRequest $request): JsonResponse
    {
        $transaction = $request->user()->transactions()->create($request->validated());

        return TransactionResource::make($transaction->load('category'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        Gate::authorize('view', $transaction);

        return TransactionResource::make($transaction->load('category'));
    }

    public function update(TransactionRequest $request, Transaction $transaction): TransactionResource
    {
        Gate::authorize('update', $transaction);

        $transaction->update($request->validated());

        return TransactionResource::make($transaction->load('category'));
    }

    public function destroy(Transaction $transaction): Response
    {
        Gate::authorize('delete', $transaction);

        $transaction->delete();

        return response()->noContent();
    }
}
