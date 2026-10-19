<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CardInvoiceController;
use App\Http\Controllers\Api\CardPrepaymentController;
use App\Http\Controllers\Api\CardPurchaseController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CreditCardController;
use App\Http\Controllers\Api\SummaryController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());

    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('budgets', BudgetController::class)->except('show');

    // Cartão de crédito
    Route::apiResource('cards', CreditCardController::class);
    Route::post('/cards/{card}/simulate', [CardPurchaseController::class, 'simulate']);
    Route::get('/cards/{card}/invoice', CardInvoiceController::class);
    Route::apiResource('cards.purchases', CardPurchaseController::class)
        ->shallow()
        ->only(['index', 'store', 'show', 'destroy']);

    // Antecipação de parcelas
    Route::post('/purchases/{purchase}/prepayments/simulate', [CardPrepaymentController::class, 'simulate']);
    Route::post('/purchases/{purchase}/prepayments', [CardPrepaymentController::class, 'store']);

    Route::get('/summary', SummaryController::class);
});
