<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->categories()->orderBy('name');

        // Filtro opcional: /api/categories?type=expense
        if (TransactionType::tryFrom((string) $request->query('type'))) {
            $query->where('type', $request->query('type'));
        }

        return CategoryResource::collection($query->get());
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $request->user()->categories()->create($request->validated());

        return CategoryResource::make($category)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Category $category): CategoryResource
    {
        Gate::authorize('view', $category);

        return CategoryResource::make($category);
    }

    public function update(CategoryRequest $request, Category $category): CategoryResource
    {
        Gate::authorize('update', $category);

        $category->update($request->validated());

        return CategoryResource::make($category);
    }

    public function destroy(Category $category): Response|JsonResponse
    {
        Gate::authorize('delete', $category);

        if ($category->transactions()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir uma categoria que possui transações.',
            ], 409);
        }

        $category->delete();

        return response()->noContent();
    }
}
