<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\CardPurchase;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * As parcelas do cartão entram nas despesas do resumo e nos orçamentos,
 * no mês em que o dinheiro sai da conta.
 */
class CardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Usuário logado, cartão (fecha dia 5, vence dia 15) e uma categoria de despesa. */
    private function prepare(): array
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $card = CreditCard::factory()->for($user)->create([
            'credit_limit' => 10000, 'closing_day' => 5, 'due_day' => 15,
        ]);
        $category = Category::factory()->for($user)->create(['name' => 'Eletrônicos', 'type' => 'expense']);

        return [$user, $card, $category];
    }

    private function buy(CreditCard $card, Category $category, float $total, int $count, float $rate = 0): int
    {
        return $this->postJson("/api/cards/{$card->id}/purchases", [
            'description' => 'Compra no cartão',
            'category_id' => $category->id,
            'total_amount' => $total,
            'installments' => $count,
            'interest_rate' => $rate,
            'purchase_date' => '2026-10-03',
        ])->assertCreated()->json('data.id');
    }

    public function test_summary_counts_card_installments_in_the_due_month(): void
    {
        [$user, $card, $category] = $this->prepare();

        // 100,00 em 3x: 33,34 em out, 33,33 em nov e 33,33 em dez.
        $this->buy($card, $category, 100, 3);
        Transaction::factory()->for($user)->create([
            'category_id' => $category->id, 'amount' => 10, 'date' => '2026-10-20',
        ]);

        $this->getJson('/api/summary?month=2026-10')
            ->assertOk()
            ->assertJsonPath('expense', '43.34')
            ->assertJsonPath('card_expense', '33.34')
            ->assertJsonPath('balance', '-43.34')
            ->assertJsonCount(1, 'by_category')
            ->assertJsonPath('by_category.0.category', 'Eletrônicos')
            ->assertJsonPath('by_category.0.total', '43.34')
            ->assertJsonPath('by_category.0.card', '33.34');

        $this->getJson('/api/summary?month=2026-11')
            ->assertJsonPath('expense', '33.33')
            ->assertJsonPath('card_expense', '33.33');

        $this->getJson('/api/summary?month=2026-12')
            ->assertJsonPath('expense', '33.33');

        $this->getJson('/api/summary?month=2027-01')
            ->assertJsonPath('expense', '0.00')
            ->assertJsonCount(0, 'by_category');
    }

    public function test_prepaid_installment_counts_in_the_payment_month_with_the_discounted_value(): void
    {
        [, $card, $category] = $this->prepare();

        // R$ 1.000 em 12x a 2%: parcelas de 94,56 (out, nov, dez...).
        $purchaseId = $this->buy($card, $category, 1000, 12, 2);

        // Antecipa a parcela 2 (vence 15/11) pagando em 16/10: sai por 92,71.
        $this->postJson("/api/purchases/{$purchaseId}/prepayments", [
            'numbers' => [2], 'payment_date' => '2026-10-16',
        ])->assertOk();

        // Outubro: parcela 1 (94,56) + parcela 2 antecipada (92,71).
        $this->getJson('/api/summary?month=2026-10')
            ->assertJsonPath('card_expense', '187.27');

        // Novembro: a parcela 2 já foi paga em outubro, então não pesa mais.
        $this->getJson('/api/summary?month=2026-11')
            ->assertJsonPath('card_expense', '0.00');

        $this->getJson('/api/summary?month=2026-12')
            ->assertJsonPath('card_expense', '94.56');
    }

    public function test_budget_spending_includes_card_installments(): void
    {
        [$user, $card, $category] = $this->prepare();

        Budget::factory()->for($user)->create(['category_id' => $category->id, 'amount' => 100]);
        $this->buy($card, $category, 100, 3); // 33,34 em outubro

        $this->getJson('/api/budgets?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.0.spent', '33.34')
            ->assertJsonPath('data.0.status', 'ok');

        // Mais 50,00 em transação: 83,34 no mês, que já é alerta.
        Transaction::factory()->for($user)->create([
            'category_id' => $category->id, 'amount' => 50, 'date' => '2026-10-20',
        ]);

        $response = $this->getJson('/api/budgets?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.0.spent', '83.34')
            ->assertJsonPath('data.0.status', 'warning');
        $this->assertEquals(83.3, $response->json('data.0.percent'));
    }

    public function test_other_users_card_spending_is_ignored(): void
    {
        $this->prepare();

        $foreign = CardPurchase::factory()->create();
        $foreign->installments()->create([
            'number' => 1, 'amount' => 500, 'due_date' => '2026-10-15', 'invoice_month' => '2026-10',
        ]);

        $this->getJson('/api/summary?month=2026-10')
            ->assertOk()
            ->assertJsonPath('expense', '0.00')
            ->assertJsonPath('card_expense', '0.00');
    }

    public function test_card_spending_without_category_is_grouped_as_uncategorized(): void
    {
        [, $card, $category] = $this->prepare();

        $this->buy($card, $category, 90, 1);

        // Excluir a categoria mantém a compra, só que sem categoria.
        $this->deleteJson("/api/categories/{$category->id}")->assertNoContent();

        $this->getJson('/api/summary?month=2026-10')
            ->assertOk()
            ->assertJsonPath('expense', '90.00')
            ->assertJsonPath('by_category.0.category', 'Sem categoria')
            ->assertJsonPath('by_category.0.card', '90.00');
    }
}
