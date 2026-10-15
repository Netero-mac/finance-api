<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_list_categories(): void
    {
        $this->getJson('/api/categories')->assertUnauthorized();
    }

    public function test_user_can_create_a_category(): void
    {
        $user = $this->login();

        $this->postJson('/api/categories', ['name' => 'Alimentação', 'type' => 'expense'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Alimentação')
            ->assertJsonPath('data.type', 'expense');

        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Alimentação',
        ]);
    }

    public function test_category_requires_valid_data(): void
    {
        $this->login();

        $this->postJson('/api/categories', ['name' => '', 'type' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'type']);
    }

    public function test_user_cannot_create_duplicate_category(): void
    {
        $user = $this->login();
        Category::factory()->for($user)->create(['name' => 'Lazer', 'type' => 'expense']);

        $this->postJson('/api/categories', ['name' => 'Lazer', 'type' => 'expense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_user_only_sees_own_categories(): void
    {
        $user = $this->login();
        Category::factory()->count(2)->for($user)->create();
        Category::factory()->create(); // de outro usuário

        $this->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_can_filter_categories_by_type(): void
    {
        $user = $this->login();
        Category::factory()->for($user)->create(['name' => 'Salário', 'type' => 'income']);
        Category::factory()->for($user)->create(['name' => 'Mercado', 'type' => 'expense']);

        $this->getJson('/api/categories?type=income')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Salário');
    }

    public function test_user_can_update_own_category(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['name' => 'Antigo', 'type' => 'expense']);

        $this->putJson("/api/categories/{$category->id}", ['name' => 'Novo', 'type' => 'expense'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Novo');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Novo']);
    }

    public function test_user_can_delete_own_category(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create();

        $this->deleteJson("/api/categories/{$category->id}")->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_user_cannot_access_another_users_category(): void
    {
        $this->login();
        $other = Category::factory()->create();

        $this->getJson("/api/categories/{$other->id}")->assertForbidden();
        $this->putJson("/api/categories/{$other->id}", ['name' => 'X', 'type' => 'income'])->assertForbidden();
        $this->deleteJson("/api/categories/{$other->id}")->assertForbidden();

        $this->assertDatabaseHas('categories', ['id' => $other->id]);
    }

    public function test_category_with_transactions_cannot_be_deleted(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $user->transactions()->create([
            'category_id' => $category->id,
            'description' => 'Almoço',
            'amount' => 25.50,
            'type' => 'expense',
            'date' => '2026-10-01',
        ]);

        $this->deleteJson("/api/categories/{$category->id}")->assertStatus(409);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }
}
