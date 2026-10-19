<?php

namespace Tests\Feature;

use App\Models\CardInstallment;
use App\Models\CardPurchase;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrepaymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Usuário logado, cartão (fecha dia 5, vence dia 15) e uma compra de R$ 1.000
     * em 12x a 2% ao mês feita em 03/10/2026: parcelas de R$ 94,56 que vencem
     * de 15/10/2026 em diante, uma por mês.
     */
    private function prepare(float $interest = 2, float $total = 1000, int $installments = 12): array
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $card = CreditCard::factory()->for($user)->create([
            'credit_limit' => 5000, 'closing_day' => 5, 'due_day' => 15,
        ]);
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $purchaseId = $this->postJson("/api/cards/{$card->id}/purchases", [
            'description' => 'Geladeira',
            'category_id' => $category->id,
            'total_amount' => $total,
            'installments' => $installments,
            'interest_rate' => $interest,
            'purchase_date' => '2026-10-03',
        ])->assertCreated()->json('data.id');

        return [$user, $card, $purchaseId];
    }

    public function test_simulation_discounts_interest_proportionally(): void
    {
        [, , $purchaseId] = $this->prepare();

        // Pagando em 16/10: a parcela 2 vence 30 dias depois e a 3, 60 dias depois.
        $this->postJson("/api/purchases/{$purchaseId}/prepayments/simulate", [
            'numbers' => [2, 3],
            'payment_date' => '2026-10-16',
        ])->assertOk()
            ->assertJsonPath('interest_rate', '2.00')
            ->assertJsonCount(2, 'installments')
            ->assertJsonPath('installments.0.number', 2)
            ->assertJsonPath('installments.0.days_early', 30)
            ->assertJsonPath('installments.0.original_amount', '94.56')
            ->assertJsonPath('installments.0.amount_to_pay', '92.71')
            ->assertJsonPath('installments.0.discount', '1.85')
            ->assertJsonPath('installments.1.number', 3)
            ->assertJsonPath('installments.1.days_early', 60)
            ->assertJsonPath('installments.1.amount_to_pay', '90.89')
            ->assertJsonPath('total_original', '189.12')
            ->assertJsonPath('total_to_pay', '183.60')
            ->assertJsonPath('total_savings', '5.52');
    }

    public function test_the_further_away_the_installment_the_bigger_the_discount(): void
    {
        [, , $purchaseId] = $this->prepare();

        // A última parcela (12) vence em 15/09/2027, 334 dias depois de 16/10/2026.
        $this->postJson("/api/purchases/{$purchaseId}/prepayments/simulate", [
            'numbers' => [12],
            'payment_date' => '2026-10-16',
        ])->assertOk()
            ->assertJsonPath('installments.0.days_early', 334)
            ->assertJsonPath('installments.0.amount_to_pay', '75.85');
    }

    public function test_purchase_without_interest_has_no_discount(): void
    {
        [, , $purchaseId] = $this->prepare(interest: 0, total: 300, installments: 3);

        $this->postJson("/api/purchases/{$purchaseId}/prepayments/simulate", [
            'numbers' => [2, 3],
            'payment_date' => '2026-10-16',
        ])->assertOk()
            ->assertJsonPath('total_original', '200.00')
            ->assertJsonPath('total_to_pay', '200.00')
            ->assertJsonPath('total_savings', '0.00');
    }

    public function test_paying_on_or_after_the_due_date_has_no_discount(): void
    {
        [, , $purchaseId] = $this->prepare();

        // A parcela 2 vence em 15/11/2026.
        foreach (['2026-11-15', '2026-11-20'] as $date) {
            $this->postJson("/api/purchases/{$purchaseId}/prepayments/simulate", [
                'numbers' => [2],
                'payment_date' => $date,
            ])->assertOk()
                ->assertJsonPath('installments.0.days_early', 0)
                ->assertJsonPath('installments.0.amount_to_pay', '94.56');
        }
    }

    public function test_simulation_does_not_change_anything(): void
    {
        [, , $purchaseId] = $this->prepare();

        $this->postJson("/api/purchases/{$purchaseId}/prepayments/simulate", [
            'numbers' => [2, 3],
            'payment_date' => '2026-10-16',
        ])->assertOk();

        $this->assertSame(0, CardInstallment::whereNotNull('paid_at')->count());
    }

    public function test_prepayment_marks_installments_as_paid(): void
    {
        [, , $purchaseId] = $this->prepare();

        $this->postJson("/api/purchases/{$purchaseId}/prepayments", [
            'numbers' => [2, 3],
            'payment_date' => '2026-10-16',
        ])->assertOk()
            ->assertJsonPath('total_to_pay', '183.60')
            ->assertJsonPath('total_savings', '5.52')
            ->assertJsonPath('purchase.installments.1.status', 'paid')
            ->assertJsonPath('purchase.installments.1.paid_amount', '92.71')
            ->assertJsonPath('purchase.installments.1.paid_at', '2026-10-16')
            ->assertJsonPath('purchase.installments.0.status', 'open')
            ->assertJsonPath('purchase.remaining.count', 10)
            ->assertJsonPath('purchase.remaining.amount', '945.60');

        $this->assertSame(2, CardInstallment::whereNotNull('paid_at')->count());
    }

    public function test_prepayment_frees_the_card_limit(): void
    {
        [, $card, $purchaseId] = $this->prepare();

        // 12 parcelas de 94,56 = 1.134,72 comprometidos.
        $this->getJson("/api/cards/{$card->id}")
            ->assertJsonPath('data.used', '1134.72')
            ->assertJsonPath('data.available', '3865.28');

        $this->postJson("/api/purchases/{$purchaseId}/prepayments", [
            'numbers' => [2, 3],
            'payment_date' => '2026-10-16',
        ])->assertOk();

        // Duas parcelas pagas liberam 2 x 94,56 do limite.
        $this->getJson("/api/cards/{$card->id}")
            ->assertJsonPath('data.used', '945.60')
            ->assertJsonPath('data.available', '4054.40');

        $this->getJson('/api/cards')
            ->assertJsonPath('data.0.used', '945.60');
    }

    public function test_installment_cannot_be_prepaid_twice(): void
    {
        [, , $purchaseId] = $this->prepare();

        $this->postJson("/api/purchases/{$purchaseId}/prepayments", ['numbers' => [2], 'payment_date' => '2026-10-16'])
            ->assertOk();

        $this->postJson("/api/purchases/{$purchaseId}/prepayments", ['numbers' => [2], 'payment_date' => '2026-10-16'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['numbers']);

        $this->assertSame(1, CardInstallment::whereNotNull('paid_at')->count());
    }

    public function test_prepayment_rejects_installments_that_do_not_exist(): void
    {
        [, , $purchaseId] = $this->prepare();

        $this->postJson("/api/purchases/{$purchaseId}/prepayments", ['numbers' => [2, 99]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['numbers']);

        // Nada é pago se alguma parcela for inválida.
        $this->assertSame(0, CardInstallment::whereNotNull('paid_at')->count());
    }

    public function test_prepayment_validates_the_payload(): void
    {
        [, , $purchaseId] = $this->prepare();

        $this->postJson("/api/purchases/{$purchaseId}/prepayments/simulate", ['numbers' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['numbers']);

        $this->postJson("/api/purchases/{$purchaseId}/prepayments/simulate", ['numbers' => [2], 'payment_date' => '16/10/2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_date']);

        $this->postJson("/api/purchases/{$purchaseId}/prepayments/simulate", ['numbers' => [2, 2]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['numbers.0']);
    }

    public function test_invoice_shows_prepaid_installments_as_paid(): void
    {
        [, $card, $purchaseId] = $this->prepare();

        $this->postJson("/api/purchases/{$purchaseId}/prepayments", ['numbers' => [2], 'payment_date' => '2026-10-16'])
            ->assertOk();

        // A parcela 2 estava na fatura de novembro: agora aparece como paga e não entra no total em aberto.
        $this->getJson("/api/cards/{$card->id}/invoice?month=2026-11")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.status', 'paid')
            ->assertJsonPath('items.0.paid_amount', '92.71')
            ->assertJsonPath('total', '0.00')
            ->assertJsonPath('paid_total', '92.71');

        // As demais faturas continuam em aberto.
        $this->getJson("/api/cards/{$card->id}/invoice?month=2026-12")
            ->assertJsonPath('items.0.status', 'open')
            ->assertJsonPath('total', '94.56');
    }

    public function test_purchase_with_paid_installments_cannot_be_deleted(): void
    {
        [, , $purchaseId] = $this->prepare();

        $this->postJson("/api/purchases/{$purchaseId}/prepayments", ['numbers' => [2], 'payment_date' => '2026-10-16'])
            ->assertOk();

        $this->deleteJson("/api/purchases/{$purchaseId}")->assertStatus(409);

        $this->assertDatabaseHas('card_purchases', ['id' => $purchaseId]);
    }

    public function test_user_cannot_prepay_another_users_purchase(): void
    {
        $this->prepare();
        $otherPurchase = CardPurchase::factory()->create();

        $this->postJson("/api/purchases/{$otherPurchase->id}/prepayments/simulate", ['numbers' => [1]])->assertForbidden();
        $this->postJson("/api/purchases/{$otherPurchase->id}/prepayments", ['numbers' => [1]])->assertForbidden();
    }
}
