<?php

namespace Tests\Feature;

use App\Models\CardInstallment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Protege a conta de demonstração: um erro no seeder derrubaria o deploy,
 * já que ele roda a cada inicialização do servidor.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function demoUser(): User
    {
        $this->seed(DatabaseSeeder::class);

        return User::where('email', 'demo@finance.test')->firstOrFail();
    }

    public function test_there_is_data_from_january_until_the_current_month(): void
    {
        $user = $this->demoUser();

        $months = $user->transactions()->get()
            ->map(fn ($transaction) => $transaction->date->format('Y-m'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $expected = collect(range(1, now()->month))
            ->map(fn (int $month) => sprintf('%d-%02d', now()->year, $month))
            ->all();

        $this->assertSame($expected, $months);
    }

    public function test_every_month_has_income_and_expenses_in_the_summary(): void
    {
        $user = $this->demoUser();
        Sanctum::actingAs($user);

        foreach (range(1, now()->month) as $month) {
            $response = $this->getJson(sprintf('/api/summary?month=%d-%02d', now()->year, $month))->assertOk();

            $this->assertGreaterThan(0, (float) $response->json('income'));
            $this->assertGreaterThan(0, (float) $response->json('expense'));
        }
    }

    public function test_nothing_is_dated_in_the_future(): void
    {
        $user = $this->demoUser();

        $this->assertSame(0, $user->transactions()->whereDate('date', '>', today()->toDateString())->count());
    }

    public function test_current_month_budgets_show_every_status(): void
    {
        $user = $this->demoUser();
        Sanctum::actingAs($user);

        $statuses = collect($this->getJson('/api/budgets')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $budget) => [$budget['category']['name'] => $budget['status']])
            ->all();

        $this->assertEquals([
            'Alimentação' => 'exceeded',
            'Transporte' => 'warning',
            'Moradia' => 'warning',
            'Lazer' => 'ok',
        ], $statuses);
    }

    public function test_demo_card_has_paid_invoices_open_installments_and_stays_within_the_limit(): void
    {
        $user = $this->demoUser();
        Sanctum::actingAs($user);

        $card = $this->getJson('/api/cards')->assertOk()->json('data.0');

        $this->assertGreaterThan(0, (float) $card['used']);
        $this->assertLessThanOrEqual((float) $card['credit_limit'], (float) $card['used']);

        $this->assertTrue(CardInstallment::whereNotNull('paid_at')->exists());
        $this->assertTrue(CardInstallment::whereNull('paid_at')->exists());
    }
}
