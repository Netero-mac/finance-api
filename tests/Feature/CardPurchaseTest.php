<?php

namespace Tests\Feature;

use App\Models\CardPurchase;
use App\Models\Category;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardPurchaseTest extends TestCase
{
    use RefreshDatabase;

    /** Cria o usuário logado, um cartão (fecha dia 5, vence dia 15) e uma categoria de despesa. */
    private function prepare(array $cardOverrides = []): array
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $card = CreditCard::factory()->for($user)->create(array_merge([
            'credit_limit' => 5000,
            'closing_day' => 5,
            'due_day' => 15,
        ], $cardOverrides));

        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        return [$user, $card, $category];
    }

    private function payload(Category $category, array $overrides = []): array
    {
        return array_merge([
            'description' => 'Notebook',
            'category_id' => $category->id,
            'total_amount' => 100,
            'installments' => 3,
            'interest_rate' => 0,
            'purchase_date' => '2026-10-03',
        ], $overrides);
    }

    /* ---------- simulação ---------- */

    public function test_simulation_without_interest_splits_cents_evenly(): void
    {
        [, $card] = $this->prepare();

        $response = $this->postJson("/api/cards/{$card->id}/simulate", [
            'total_amount' => 100,
            'installments' => 3,
            'purchase_date' => '2026-10-03',
        ])->assertOk()
            ->assertJsonPath('installments_count', 3)
            ->assertJsonPath('total_with_interest', '100.00')
            ->assertJsonPath('interest_total', '0.00')
            ->assertJsonPath('installment_amount', '33.34')
            ->assertJsonPath('first_due_date', '2026-10-15')
            ->assertJsonPath('fits_limit', true);

        $response->assertJsonPath('installments.0.amount', '33.34')
            ->assertJsonPath('installments.1.amount', '33.33')
            ->assertJsonPath('installments.2.amount', '33.33')
            ->assertJsonPath('installments.1.due_date', '2026-11-15')
            ->assertJsonPath('installments.2.invoice_month', '2026-12');
    }

    public function test_simulation_with_interest_uses_the_price_table(): void
    {
        [, $card] = $this->prepare();

        // R$ 1.000,00 em 12x a 2% ao mês: parcela de R$ 94,56 (Tabela Price).
        $this->postJson("/api/cards/{$card->id}/simulate", [
            'total_amount' => 1000,
            'installments' => 12,
            'interest_rate' => 2,
            'purchase_date' => '2026-10-03',
        ])->assertOk()
            ->assertJsonPath('installment_amount', '94.56')
            ->assertJsonPath('total_with_interest', '1134.72')
            ->assertJsonPath('interest_total', '134.72')
            ->assertJsonCount(12, 'installments');
    }

    public function test_simulation_reports_when_purchase_does_not_fit_the_limit(): void
    {
        [, $card] = $this->prepare(['credit_limit' => 500]);

        $this->postJson("/api/cards/{$card->id}/simulate", [
            'total_amount' => 600,
            'installments' => 2,
        ])->assertOk()
            ->assertJsonPath('fits_limit', false)
            ->assertJsonPath('available_limit', '500.00');
    }

    public function test_purchase_after_closing_day_goes_to_next_invoice(): void
    {
        [, $card] = $this->prepare(); // fecha dia 5, vence dia 15

        // Até o dia 5 (inclusive): fatura que vence em outubro.
        $this->postJson("/api/cards/{$card->id}/simulate", ['total_amount' => 100, 'installments' => 1, 'purchase_date' => '2026-10-05'])
            ->assertJsonPath('first_due_date', '2026-10-15');

        // Depois do fechamento: fatura de novembro.
        $this->postJson("/api/cards/{$card->id}/simulate", ['total_amount' => 100, 'installments' => 1, 'purchase_date' => '2026-10-06'])
            ->assertJsonPath('first_due_date', '2026-11-15');
    }

    public function test_invoice_that_is_due_in_the_month_after_closing(): void
    {
        [, $card] = $this->prepare(['closing_day' => 25, 'due_day' => 5]);

        $this->postJson("/api/cards/{$card->id}/simulate", ['total_amount' => 100, 'installments' => 1, 'purchase_date' => '2026-10-20'])
            ->assertJsonPath('first_due_date', '2026-11-05');

        $this->postJson("/api/cards/{$card->id}/simulate", ['total_amount' => 100, 'installments' => 1, 'purchase_date' => '2026-10-26'])
            ->assertJsonPath('first_due_date', '2026-12-05');
    }

    /* ---------- compras ---------- */

    public function test_user_can_register_an_installment_purchase(): void
    {
        [, $card, $category] = $this->prepare();

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category))
            ->assertCreated()
            ->assertJsonPath('data.description', 'Notebook')
            ->assertJsonPath('data.total_amount', '100.00')
            ->assertJsonPath('data.installments_count', 3)
            ->assertJsonPath('data.total_with_interest', '100.00')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonCount(3, 'data.installments')
            ->assertJsonPath('data.installments.0.amount', '33.34');

        $this->assertDatabaseCount('card_installments', 3);
    }

    public function test_purchase_with_interest_stores_the_financed_total(): void
    {
        [, $card, $category] = $this->prepare();

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, [
            'total_amount' => 1000,
            'installments' => 12,
            'interest_rate' => 2,
        ]))->assertCreated()
            ->assertJsonPath('data.total_amount', '1000.00')
            ->assertJsonPath('data.total_with_interest', '1134.72')
            ->assertJsonPath('data.interest_total', '134.72')
            ->assertJsonPath('data.installments.0.amount', '94.56');
    }

    public function test_purchase_reduces_the_available_limit(): void
    {
        [, $card, $category] = $this->prepare(['credit_limit' => 1000]);

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, ['total_amount' => 300]))
            ->assertCreated();

        $this->getJson("/api/cards/{$card->id}")
            ->assertOk()
            ->assertJsonPath('data.used', '300.00')
            ->assertJsonPath('data.available', '700.00');

        // O que sobrou (R$ 700) não comporta uma compra de R$ 800.
        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, ['total_amount' => 800]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['total_amount']);

        $this->assertDatabaseCount('card_purchases', 1);
    }

    public function test_deleting_a_purchase_removes_installments_and_frees_the_limit(): void
    {
        [, $card, $category] = $this->prepare(['credit_limit' => 1000]);

        $id = $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, ['total_amount' => 300]))
            ->json('data.id');

        $this->deleteJson("/api/purchases/{$id}")->assertNoContent();

        $this->assertDatabaseCount('card_installments', 0);
        $this->getJson("/api/cards/{$card->id}")->assertJsonPath('data.used', '0.00');
    }

    public function test_purchase_requires_an_own_expense_category(): void
    {
        [$user, $card] = $this->prepare();
        $income = Category::factory()->for($user)->create(['type' => 'income']);
        $foreign = Category::factory()->create(['type' => 'expense']);

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($income))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($foreign))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_purchase_validates_installments_and_interest(): void
    {
        [, $card, $category] = $this->prepare();

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, ['installments' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['installments']);

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, ['installments' => 49]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['installments']);

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, ['interest_rate' => -1]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['interest_rate']);
    }

    public function test_user_can_list_card_purchases(): void
    {
        [, $card, $category] = $this->prepare();

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category))->assertCreated();
        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, ['description' => 'Fone']))->assertCreated();

        $this->getJson("/api/cards/{$card->id}/purchases")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_cannot_use_another_users_card_or_purchase(): void
    {
        [, , $category] = $this->prepare();
        $otherCard = CreditCard::factory()->create();
        $otherPurchase = CardPurchase::factory()->create();

        $this->getJson("/api/cards/{$otherCard->id}/purchases")->assertForbidden();
        $this->postJson("/api/cards/{$otherCard->id}/purchases", $this->payload($category))->assertForbidden();
        $this->postJson("/api/cards/{$otherCard->id}/simulate", ['total_amount' => 100, 'installments' => 1])->assertForbidden();
        $this->getJson("/api/cards/{$otherCard->id}/invoice")->assertForbidden();
        $this->getJson("/api/purchases/{$otherPurchase->id}")->assertForbidden();
        $this->deleteJson("/api/purchases/{$otherPurchase->id}")->assertForbidden();

        $this->assertDatabaseHas('card_purchases', ['id' => $otherPurchase->id]);
    }

    /* ---------- fatura ---------- */

    public function test_invoice_groups_installments_due_in_the_month(): void
    {
        [, $card, $category] = $this->prepare(); // fecha dia 5, vence dia 15

        // A: 100,00 em 3x (33,34 / 33,33 / 33,33), vence 15/10, 15/11 e 15/12.
        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, [
            'description' => 'Compra A', 'total_amount' => 100, 'installments' => 3, 'purchase_date' => '2026-10-03',
        ]))->assertCreated();

        // B: depois do fechamento, então a 1ª parcela vai para novembro. 60,00 em 2x.
        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, [
            'description' => 'Compra B', 'total_amount' => 60, 'installments' => 2, 'purchase_date' => '2026-10-10',
        ]))->assertCreated();

        $this->getJson("/api/cards/{$card->id}/invoice?month=2026-11")
            ->assertOk()
            ->assertJsonPath('month', '2026-11')
            ->assertJsonPath('due_date', '2026-11-15')
            ->assertJsonPath('closing_date', '2026-11-05')
            ->assertJsonPath('total', '63.33')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.description', 'Compra A')
            ->assertJsonPath('items.0.number', 2)
            ->assertJsonPath('items.0.count', 3)
            ->assertJsonPath('items.0.amount', '33.33')
            ->assertJsonPath('items.1.description', 'Compra B')
            ->assertJsonPath('items.1.amount', '30.00');

        $this->getJson("/api/cards/{$card->id}/invoice?month=2026-10")
            ->assertOk()
            ->assertJsonPath('total', '33.34')
            ->assertJsonCount(1, 'items');
    }

    public function test_invoice_closing_date_when_it_closes_in_the_previous_month(): void
    {
        [, $card, $category] = $this->prepare(['closing_day' => 25, 'due_day' => 5]);

        $this->postJson("/api/cards/{$card->id}/purchases", $this->payload($category, [
            'total_amount' => 90, 'installments' => 1, 'purchase_date' => '2026-10-26',
        ]))->assertCreated();

        $this->getJson("/api/cards/{$card->id}/invoice?month=2026-12")
            ->assertOk()
            ->assertJsonPath('closing_date', '2026-11-25')
            ->assertJsonPath('due_date', '2026-12-05')
            ->assertJsonPath('total', '90.00');
    }

    public function test_empty_invoice_has_zero_total(): void
    {
        [, $card] = $this->prepare();

        $this->getJson("/api/cards/{$card->id}/invoice?month=2026-10")
            ->assertOk()
            ->assertJsonPath('total', '0.00')
            ->assertJsonCount(0, 'items');
    }

    public function test_invoice_rejects_invalid_month(): void
    {
        [, $card] = $this->prepare();

        $this->getJson("/api/cards/{$card->id}/invoice?month=abc")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }
}
