<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(Category $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'description' => 'Almoço',
            'amount' => 25.50,
            'type' => $category->type->value,
            'date' => '2026-10-01',
        ], $overrides);
    }

    public function test_guest_cannot_list_transactions(): void
    {
        $this->getJson('/api/transactions')->assertUnauthorized();
    }

    public function test_user_can_create_a_transaction(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->postJson('/api/transactions', $this->payload($category))
            ->assertCreated()
            ->assertJsonPath('data.description', 'Almoço')
            ->assertJsonPath('data.amount', '25.50')
            ->assertJsonPath('data.category.id', $category->id);

        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'description' => 'Almoço',
        ]);
    }

    public function test_cannot_use_another_users_category(): void
    {
        $this->login();
        $foreignCategory = Category::factory()->create(['type' => 'expense']);

        $this->postJson('/api/transactions', $this->payload($foreignCategory))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_transaction_type_must_match_category_type(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->postJson('/api/transactions', $this->payload($category, ['type' => 'income']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_amount_must_be_positive(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->postJson('/api/transactions', $this->payload($category, ['amount' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_user_only_sees_own_transactions(): void
    {
        $user = $this->login();
        Transaction::factory()->count(3)->for($user)->create();
        Transaction::factory()->count(2)->create(); // de outros usuários

        $this->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_user_can_filter_transactions_by_period(): void
    {
        $user = $this->login();
        Transaction::factory()->for($user)->create(['date' => '2026-09-10']);
        Transaction::factory()->for($user)->create(['date' => '2026-10-10']);

        $this->getJson('/api/transactions?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-10-10');
    }

    public function test_user_can_filter_transactions_by_category_and_type(): void
    {
        $user = $this->login();
        $food = Category::factory()->for($user)->create(['name' => 'Alimentação', 'type' => 'expense']);
        $salary = Category::factory()->for($user)->create(['name' => 'Salário', 'type' => 'income']);

        Transaction::factory()->count(2)->for($user)->create(['category_id' => $food->id]);
        Transaction::factory()->for($user)->create(['category_id' => $salary->id]);

        $this->getJson("/api/transactions?category_id={$food->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/transactions?type=income')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_transactions_are_paginated(): void
    {
        $user = $this->login();
        Transaction::factory()->count(5)->for($user)->create();

        $this->getJson('/api/transactions?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5);
    }

    public function test_user_can_update_own_transaction(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $transaction = Transaction::factory()->for($user)->create(['category_id' => $category->id]);

        $this->putJson("/api/transactions/{$transaction->id}", $this->payload($category, [
            'description' => 'Jantar',
            'amount' => 80,
        ]))
            ->assertOk()
            ->assertJsonPath('data.description', 'Jantar');

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'description' => 'Jantar']);
    }

    public function test_user_can_delete_own_transaction(): void
    {
        $user = $this->login();
        $transaction = Transaction::factory()->for($user)->create();

        $this->deleteJson("/api/transactions/{$transaction->id}")->assertNoContent();

        $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    }

    public function test_user_cannot_access_another_users_transaction(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);
        $other = Transaction::factory()->create();

        $this->getJson("/api/transactions/{$other->id}")->assertForbidden();
        $this->putJson("/api/transactions/{$other->id}", $this->payload($category))->assertForbidden();
        $this->deleteJson("/api/transactions/{$other->id}")->assertForbidden();

        $this->assertDatabaseHas('transactions', ['id' => $other->id]);
    }
}
