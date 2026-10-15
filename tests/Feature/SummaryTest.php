<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SummaryTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_guest_cannot_see_summary(): void
    {
        $this->getJson('/api/summary')->assertUnauthorized();
    }

    public function test_summary_totals_income_expense_and_balance(): void
    {
        $user = $this->login();
        $salary = Category::factory()->for($user)->create(['name' => 'Salário', 'type' => 'income']);
        $food = Category::factory()->for($user)->create(['name' => 'Alimentação', 'type' => 'expense']);

        Transaction::factory()->for($user)->create(['category_id' => $salary->id, 'amount' => 1000, 'date' => '2026-10-05']);
        Transaction::factory()->for($user)->create(['category_id' => $food->id, 'amount' => 200.50, 'date' => '2026-10-10']);
        Transaction::factory()->for($user)->create(['category_id' => $food->id, 'amount' => 99.50, 'date' => '2026-10-31']);

        $this->getJson('/api/summary?month=2026-10')
            ->assertOk()
            ->assertJsonPath('month', '2026-10')
            ->assertJsonPath('income', '1000.00')
            ->assertJsonPath('expense', '300.00')
            ->assertJsonPath('balance', '700.00')
            ->assertJsonCount(2, 'by_category')
            ->assertJsonPath('by_category.0.category', 'Salário')
            ->assertJsonPath('by_category.0.total', '1000.00')
            ->assertJsonPath('by_category.1.category', 'Alimentação')
            ->assertJsonPath('by_category.1.total', '300.00');
    }

    public function test_summary_ignores_other_months(): void
    {
        $user = $this->login();
        $food = Category::factory()->for($user)->create(['type' => 'expense']);

        Transaction::factory()->for($user)->create(['category_id' => $food->id, 'amount' => 50, 'date' => '2026-09-30']);
        Transaction::factory()->for($user)->create(['category_id' => $food->id, 'amount' => 70, 'date' => '2026-11-01']);

        $this->getJson('/api/summary?month=2026-10')
            ->assertOk()
            ->assertJsonPath('expense', '0.00')
            ->assertJsonPath('balance', '0.00')
            ->assertJsonCount(0, 'by_category');
    }

    public function test_summary_ignores_other_users_transactions(): void
    {
        $user = $this->login();
        $mine = Category::factory()->for($user)->create(['type' => 'expense']);

        Transaction::factory()->for($user)->create(['category_id' => $mine->id, 'amount' => 10, 'date' => '2026-10-05']);
        Transaction::factory()->create(['amount' => 999, 'date' => '2026-10-05']); // de outro usuário

        $this->getJson('/api/summary?month=2026-10')
            ->assertOk()
            ->assertJsonPath('expense', '10.00');
    }

    public function test_summary_uses_current_month_by_default(): void
    {
        $user = $this->login();
        $salary = Category::factory()->for($user)->create(['type' => 'income']);

        Transaction::factory()->for($user)->create([
            'category_id' => $salary->id,
            'amount' => 500,
            'date' => now()->toDateString(),
        ]);

        $this->getJson('/api/summary')
            ->assertOk()
            ->assertJsonPath('month', now()->format('Y-m'))
            ->assertJsonPath('income', '500.00');
    }

    public function test_summary_rejects_invalid_month(): void
    {
        $this->login();

        $this->getJson('/api/summary?month=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }
}
