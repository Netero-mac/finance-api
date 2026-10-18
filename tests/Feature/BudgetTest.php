<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private function login(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Cria categoria de despesa + orçamento + (opcional) uma despesa já lançada. */
    private function budgetWithSpent(User $user, string $name, float $limit, float $spent, string $date = '2026-10-10'): Budget
    {
        $category = Category::factory()->for($user)->create(['name' => $name, 'type' => 'expense']);
        $budget = Budget::factory()->for($user)->create(['category_id' => $category->id, 'amount' => $limit]);

        if ($spent > 0) {
            Transaction::factory()->for($user)->create([
                'category_id' => $category->id,
                'amount' => $spent,
                'date' => $date,
            ]);
        }

        return $budget;
    }

    public function test_guest_cannot_list_budgets(): void
    {
        $this->getJson('/api/budgets')->assertUnauthorized();
    }

    public function test_user_can_create_a_budget(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->postJson('/api/budgets', ['category_id' => $category->id, 'amount' => 800])
            ->assertCreated()
            ->assertJsonPath('data.amount', '800.00')
            ->assertJsonPath('data.spent', '0.00')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.category.id', $category->id);

        $this->assertDatabaseHas('budgets', ['user_id' => $user->id, 'category_id' => $category->id]);
    }

    public function test_budget_only_accepts_expense_categories(): void
    {
        $user = $this->login();
        $income = Category::factory()->for($user)->create(['type' => 'income']);

        $this->postJson('/api/budgets', ['category_id' => $income->id, 'amount' => 800])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_budget_cannot_use_another_users_category(): void
    {
        $this->login();
        $foreign = Category::factory()->create(['type' => 'expense']);

        $this->postJson('/api/budgets', ['category_id' => $foreign->id, 'amount' => 800])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_only_one_budget_per_category(): void
    {
        $user = $this->login();
        $budget = $this->budgetWithSpent($user, 'Lazer', 500, 0);

        $this->postJson('/api/budgets', ['category_id' => $budget->category_id, 'amount' => 900])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_amount_must_be_positive(): void
    {
        $user = $this->login();
        $category = Category::factory()->for($user)->create(['type' => 'expense']);

        $this->postJson('/api/budgets', ['category_id' => $category->id, 'amount' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_budget_progress_statuses(): void
    {
        $user = $this->login();
        $this->budgetWithSpent($user, 'A', 1000, 500);   // 50%  -> ok
        $this->budgetWithSpent($user, 'B', 1000, 850);   // 85%  -> warning
        $this->budgetWithSpent($user, 'C', 1000, 1100);  // 110% -> exceeded
        $this->budgetWithSpent($user, 'D', 100, 100);    // 100% -> warning (ainda não estourou)

        $response = $this->getJson('/api/budgets?month=2026-10')
            ->assertOk()
            ->assertJsonPath('month', '2026-10')
            ->assertJsonCount(4, 'data');

        $response->assertJsonPath('data.0.category.name', 'A')
            ->assertJsonPath('data.0.status', 'ok')
            ->assertJsonPath('data.0.spent', '500.00')
            ->assertJsonPath('data.0.remaining', '500.00');
        $this->assertEquals(50.0, $response->json('data.0.percent'));

        $response->assertJsonPath('data.1.status', 'warning');
        $this->assertEquals(85.0, $response->json('data.1.percent'));

        $response->assertJsonPath('data.2.status', 'exceeded')
            ->assertJsonPath('data.2.remaining', '-100.00');
        $this->assertEquals(110.0, $response->json('data.2.percent'));

        $response->assertJsonPath('data.3.status', 'warning');
        $this->assertEquals(100.0, $response->json('data.3.percent'));
    }

    public function test_budget_only_counts_expenses_of_the_selected_month(): void
    {
        $user = $this->login();
        $budget = $this->budgetWithSpent($user, 'Mercado', 1000, 300, '2026-10-10');

        // Gasto em outro mês e gasto de outro usuário não entram na conta.
        Transaction::factory()->for($user)->create([
            'category_id' => $budget->category_id, 'amount' => 400, 'date' => '2026-09-30',
        ]);
        Transaction::factory()->create(['amount' => 999, 'date' => '2026-10-10']);

        $this->getJson('/api/budgets?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.0.spent', '300.00');

        $this->getJson('/api/budgets?month=2026-09')
            ->assertOk()
            ->assertJsonPath('data.0.spent', '400.00');
    }

    public function test_user_only_sees_own_budgets(): void
    {
        $user = $this->login();
        $this->budgetWithSpent($user, 'Meu', 500, 0);
        Budget::factory()->create(); // de outro usuário

        $this->getJson('/api/budgets')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_budgets_reject_invalid_month(): void
    {
        $this->login();

        $this->getJson('/api/budgets?month=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_user_can_update_budget_amount(): void
    {
        $user = $this->login();
        $budget = $this->budgetWithSpent($user, 'Lazer', 500, 0);

        $this->putJson("/api/budgets/{$budget->id}", ['amount' => 1500])
            ->assertOk()
            ->assertJsonPath('data.amount', '1500.00');

        $this->assertDatabaseHas('budgets', ['id' => $budget->id, 'amount' => 1500]);
    }

    public function test_user_can_delete_budget(): void
    {
        $user = $this->login();
        $budget = $this->budgetWithSpent($user, 'Lazer', 500, 0);

        $this->deleteJson("/api/budgets/{$budget->id}")->assertNoContent();

        $this->assertDatabaseMissing('budgets', ['id' => $budget->id]);
    }

    public function test_user_cannot_change_another_users_budget(): void
    {
        $this->login();
        $other = Budget::factory()->create();

        $this->putJson("/api/budgets/{$other->id}", ['amount' => 1])->assertForbidden();
        $this->deleteJson("/api/budgets/{$other->id}")->assertForbidden();

        $this->assertDatabaseHas('budgets', ['id' => $other->id]);
    }
}
