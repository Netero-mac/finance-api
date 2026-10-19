<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\CardPurchasePlanner;
use App\Services\EarlyPaymentCalculator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Cria um usuário de demonstração com três meses de movimentações,
     * orçamentos em situações diferentes (ok, alerta e estourado) e um cartão
     * de crédito com compras parceladas, uma delas com parcelas antecipadas.
     *
     * Login: demo@finance.test / demo12345
     */
    public function run(): void
    {
        $user = User::create([
            'name' => 'Usuário Demo',
            'email' => 'demo@finance.test',
            'password' => 'demo12345', // o cast "hashed" do model cuida do hash
        ]);

        $categories = [];
        foreach ([
            ['Salário', 'income'],
            ['Freelance', 'income'],
            ['Alimentação', 'expense'],
            ['Moradia', 'expense'],
            ['Transporte', 'expense'],
            ['Lazer', 'expense'],
            ['Compras', 'expense'],
        ] as [$name, $type]) {
            $categories[$name] = $user->categories()->create(['name' => $name, 'type' => $type]);
        }

        // Mês atual e os dois anteriores.
        foreach ([0, 1, 2] as $monthsAgo) {
            $month = now()->startOfMonth()->subMonthsNoOverflow($monthsAgo);

            $entries = [
                ['Salário', 'Salário do mês', 4500.00, 5],
                ['Moradia', 'Aluguel', 1200.00, 10],
                ['Alimentação', 'Supermercado', 450.75 + $monthsAgo * 20, 8],
                ['Alimentação', 'Feira e padaria', 120.30, 14],
                ['Alimentação', 'Restaurante', 85.90 + $monthsAgo * 15, 21],
                ['Transporte', 'Combustível', 210.00, 12],
                ['Lazer', 'Cinema e jantar', 95.90 + $monthsAgo * 10, 18],
            ];

            if ($monthsAgo !== 1) {
                $entries[] = ['Freelance', 'Projeto de site', 800.00, 15];
            }

            foreach ($entries as [$category, $description, $amount, $day]) {
                $user->transactions()->create([
                    'category_id' => $categories[$category]->id,
                    'description' => $description,
                    'amount' => $amount,
                    'type' => $categories[$category]->type,
                    'date' => $month->copy()->day($day)->toDateString(),
                ]);
            }
        }

        // Orçamentos mensais. No mês atual:
        //  Lazer       95,90 de 150    -> ok
        //  Transporte 210,00 de 250    -> alerta (84%)
        //  Moradia   1200,00 de 1200   -> alerta (100%)
        //  Alimentação 656,95 de 600   -> estourado
        foreach ([
            ['Alimentação', 600],
            ['Transporte', 250],
            ['Lazer', 150],
            ['Moradia', 1200],
        ] as [$name, $limit]) {
            $user->budgets()->create([
                'category_id' => $categories[$name]->id,
                'amount' => $limit,
            ]);
        }

        $this->seedCreditCard($user, $categories);
    }

    private function seedCreditCard(User $user, array $categories): void
    {
        $planner = app(CardPurchasePlanner::class);

        $card = $user->creditCards()->create([
            'name' => 'Cartão Principal',
            'credit_limit' => 6000,
            'closing_day' => 5,
            'due_day' => 15,
        ]);

        $startOfMonth = now()->startOfMonth();

        // [descrição, categoria, valor, parcelas, juros ao mês (%), data da compra]
        $purchases = [
            ['Geladeira', 'Compras', 2400, 10, 2.0, $startOfMonth->copy()->subMonthsNoOverflow(2)->day(3)],
            ['Celular', 'Compras', 1800, 6, 0, $startOfMonth->copy()->subMonthsNoOverflow(1)->day(8)],
            ['Fone de ouvido', 'Lazer', 360, 3, 0, $startOfMonth->copy()->day(2)],
        ];

        $created = [];
        foreach ($purchases as [$description, $category, $total, $count, $rate, $date]) {
            $plan = $planner->plan($card, $total, $count, $rate, $date);

            $purchase = $card->purchases()->create([
                'category_id' => $categories[$category]->id,
                'description' => $description,
                'total_amount' => CardPurchasePlanner::format($plan['principal_cents']),
                'installments_count' => count($plan['installments']),
                'interest_rate' => $rate,
                'total_with_interest' => CardPurchasePlanner::format($plan['total_cents']),
                'purchase_date' => $date->toDateString(),
            ]);

            $purchase->installments()->createMany(array_map(fn (array $item) => [
                'number' => $item['number'],
                'amount' => CardPurchasePlanner::format($item['amount_cents']),
                'due_date' => $item['due_date']->toDateString(),
                'invoice_month' => $item['invoice_month'],
            ], $plan['installments']));

            $created[$description] = $purchase;
        }

        // Antecipa as duas últimas parcelas da geladeira, para a demonstração
        // mostrar o desconto de juros e o limite liberado.
        $calculator = app(EarlyPaymentCalculator::class);
        $rate = (float) $created['Geladeira']->interest_rate;

        foreach ($created['Geladeira']->installments()->whereIn('number', [9, 10])->get() as $installment) {
            $quote = $calculator->quote($installment, $rate, Carbon::today());

            $installment->forceFill([
                'paid_at' => now(),
                'paid_amount' => CardPurchasePlanner::format($quote['discounted_cents']),
            ])->save();
        }
    }
}
