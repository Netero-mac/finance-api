<?php

namespace Tests\Feature;

use App\Models\CardPurchase;
use App\Models\CreditCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditCardTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cartão Principal',
            'credit_limit' => 5000,
            'closing_day' => 5,
            'due_day' => 15,
        ], $overrides);
    }

    public function test_guest_cannot_list_cards(): void
    {
        $this->getJson('/api/cards')->assertUnauthorized();
    }

    public function test_user_can_create_a_card(): void
    {
        $user = $this->login();

        $this->postJson('/api/cards', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cartão Principal')
            ->assertJsonPath('data.credit_limit', '5000.00')
            ->assertJsonPath('data.used', '0.00')
            ->assertJsonPath('data.available', '5000.00');

        $this->assertDatabaseHas('credit_cards', ['user_id' => $user->id, 'name' => 'Cartão Principal']);
    }

    public function test_card_days_must_be_between_1_and_28(): void
    {
        $this->login();

        $this->postJson('/api/cards', $this->payload(['closing_day' => 29]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['closing_day']);

        $this->postJson('/api/cards', $this->payload(['due_day' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_day']);
    }

    public function test_card_requires_a_positive_limit(): void
    {
        $this->login();

        $this->postJson('/api/cards', $this->payload(['credit_limit' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credit_limit']);
    }

    public function test_user_only_sees_own_cards(): void
    {
        $user = $this->login();
        CreditCard::factory()->count(2)->for($user)->create();
        CreditCard::factory()->create(); // de outro usuário

        $this->getJson('/api/cards')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_can_update_own_card(): void
    {
        $user = $this->login();
        $card = CreditCard::factory()->for($user)->create();

        $this->putJson("/api/cards/{$card->id}", $this->payload(['name' => 'Novo nome', 'credit_limit' => 8000]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Novo nome')
            ->assertJsonPath('data.credit_limit', '8000.00');
    }

    public function test_user_can_delete_own_card(): void
    {
        $user = $this->login();
        $card = CreditCard::factory()->for($user)->create();

        $this->deleteJson("/api/cards/{$card->id}")->assertNoContent();

        $this->assertDatabaseMissing('credit_cards', ['id' => $card->id]);
    }

    public function test_card_with_purchases_cannot_be_deleted(): void
    {
        $user = $this->login();
        $card = CreditCard::factory()->for($user)->create();
        CardPurchase::factory()->for($card, 'card')->create();

        $this->deleteJson("/api/cards/{$card->id}")->assertStatus(409);

        $this->assertDatabaseHas('credit_cards', ['id' => $card->id]);
    }

    public function test_user_cannot_access_another_users_card(): void
    {
        $this->login();
        $other = CreditCard::factory()->create();

        $this->getJson("/api/cards/{$other->id}")->assertForbidden();
        $this->putJson("/api/cards/{$other->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/cards/{$other->id}")->assertForbidden();

        $this->assertDatabaseHas('credit_cards', ['id' => $other->id]);
    }
}
