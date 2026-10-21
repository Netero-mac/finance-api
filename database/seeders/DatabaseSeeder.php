<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use App\Services\CardPurchasePlanner;
use App\Services\CardSpending;
use App\Services\EarlyPaymentCalculator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Conta de demonstração com dados de janeiro até o mês atual:
     * receitas e despesas de todos os meses, orçamentos em situações diferentes
     * (ok, alerta e estourado) e um cartão de crédito com compras parceladas.
     *
     * Os valores variam de mês a mês, mas de forma determinística (sem aleatoriedade),
     * e nada é lançado com data futura. Como o seeder roda a cada inicialização do
     * servidor de demonstração, os dados acompanham a data de hoje.
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

        $categories = $this->seedCategories($user);
        $spentThisMonth = $this->seedTransactions($user, $categories);
        $this->seedCreditCard($user, $categories);
        $this->seedBudgets($user, $categories, $spentThisMonth);
    }

    /** @return array<string, Category> */
    private function seedCategories(User $user): array
    {
        $categories = [];

        foreach ([
            ['Salário', 'income'],
            ['Freelance', 'income'],
            ['Alimentação', 'expense'],
            ['Moradia', 'expense'],
            ['Transporte', 'expense'],
            ['Lazer', 'expense'],
            ['Saúde', 'expense'],
            ['Compras', 'expense'],
        ] as [$name, $type]) {
            $categories[$name] = $user->categories()->create(['name' => $name, 'type' => $type]);
        }

        return $categories;
    }

    /**
     * Lança as transações de janeiro até o mês atual.
     *
     * @return array<string, float> gasto do mês atual por categoria (usado para calibrar os orçamentos)
     */
    private function seedTransactions(User $user, array $categories): array
    {
        $today = Carbon::today();
        $spentThisMonth = [];

        foreach (range(1, $today->month) as $m) {
            $isCurrentMonth = $m === $today->month;

            // Variação suave e repetível: o valor oscila em torno de uma base.
            $vary = fn (float $base, int $salt, float $pct = 0.12) => round(
                $base * (1 + $pct * sin($m * 1.7 + $salt)),
                2
            );

            $entries = [
                // [categoria, descrição, valor, dia]
                ['Salário', 'Salário do mês', $m >= 6 ? 4800.00 : 4500.00, 5],

                ['Moradia', 'Aluguel', $m >= 7 ? 1280.00 : 1200.00, 10],
                ['Moradia', 'Conta de luz', $vary(170, 3, 0.25), 20],
                ['Moradia', 'Internet', 99.90, 22],

                ['Alimentação', 'Supermercado', $vary(430, 5), 8],
                ['Alimentação', 'Feira e padaria', $vary(130, 7), 14],
                ['Alimentação', 'Restaurante', $vary(95, 9, 0.3), 21],
                ['Alimentação', 'Delivery', $vary(60, 13, 0.4), 26],

                ['Transporte', 'Combustível', $vary(210, 15), 12],
                ['Transporte', 'Combustível', $vary(190, 17), 27],
                ['Transporte', 'Aplicativo de transporte', $vary(48, 19, 0.4), 18],

                ['Lazer', 'Streaming', 55.90, 3],
                ['Lazer', 'Cinema e jantar', $vary(110, 21, 0.4), 18],

                ['Saúde', 'Farmácia', $vary(70, 23, 0.5), 9],
            ];

            // Receitas e despesas que não acontecem todo mês.
            if (in_array($m, [1, 3, 4, 6, 8, 9, 10], true)) {
                $entries[] = [
                    'Freelance',
                    ['Projeto de site', 'Manutenção de site', 'Landing page', 'Consultoria'][$m % 4],
                    $vary(900, 11, 0.4),
                    15,
                ];
            }
            if (in_array($m, [3, 6, 9], true)) {
                $entries[] = ['Saúde', 'Consulta', 180.00, 16];
            }
            if ($m === 7) {
                $entries[] = ['Lazer', 'Viagem de fim de semana', 640.00, 19];
            }

            foreach ($entries as [$category, $description, $amount, $day]) {
                // No mês atual nada fica com data futura.
                $day = $isCurrentMonth ? min($day, $today->day) : $day;

                $user->transactions()->create([
                    'category_id' => $categories[$category]->id,
                    'description' => $description,
                    'amount' => $amount,
                    'type' => $categories[$category]->type,
                    'date' => Carbon::create($today->year, $m, $day)->toDateString(),
                ]);

                if ($isCurrentMonth && $categories[$category]->type->value === 'expense') {
                    $spentThisMonth[$category] = ($spentThisMonth[$category] ?? 0) + $amount;
                }
            }
        }

        return $spentThisMonth;
    }

    /**
     * Cartão com compras parceladas ao longo do ano. As faturas que já venceram aparecem
     * como pagas; as futuras ficam em aberto e comprometem o limite. Duas parcelas da
     * geladeira são antecipadas hoje, para mostrar o desconto de juros.
     */
    private function seedCreditCard(User $user, array $categories): void
    {
        $planner = app(CardPurchasePlanner::class);
        $calculator = app(EarlyPaymentCalculator::class);
        $today = Carbon::today();

        $card = $user->creditCards()->create([
            'name' => 'Cartão Principal',
            'credit_limit' => 5000,
            'closing_day' => 5,
            'due_day' => 15,
        ]);

        // [mês, dia, descrição, categoria, valor, parcelas, juros ao mês (%)]
        $purchases = [
            [1, 8, 'Notebook', 'Compras', 3600, 12, 1.9],
            [3, 12, 'Passagem aérea', 'Lazer', 960, 4, 0],
            [4, 20, 'Geladeira', 'Compras', 2400, 10, 2.0],
            [6, 3, 'Celular', 'Compras', 1800, 6, 0],
            [8, 6, 'Cadeira ergonômica', 'Compras', 890, 5, 0],
            [9, 2, 'Fone de ouvido', 'Compras', 360, 3, 0],
            [10, 2, 'Curso online', 'Compras', 450, 3, 0],
        ];

        $created = [];

        foreach ($purchases as [$month, $day, $description, $category, $total, $count, $rate]) {
            if ($month > $today->month) {
                continue; // compra de um mês que ainda não chegou
            }

            $day = $month === $today->month ? min($day, $today->day) : $day;
            $date = Carbon::create($today->year, $month, $day);
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

            // Faturas que já venceram foram pagas na data do vencimento.
            foreach ($purchase->installments()->get() as $installment) {
                if ($installment->due_date->lt($today)) {
                    $installment->forceFill([
                        'paid_at' => $installment->due_date->copy(),
                        'paid_amount' => $installment->amount,
                    ])->save();
                }
            }

            $created[$description] = $purchase;
        }

        // Antecipa as duas últimas parcelas da geladeira.
        if (isset($created['Geladeira'])) {
            $rate = (float) $created['Geladeira']->interest_rate;

            $installments = $created['Geladeira']->installments()
                ->whereIn('number', [9, 10])
                ->whereNull('paid_at')
                ->get();

            foreach ($installments as $installment) {
                $quote = $calculator->quote($installment, $rate, $today);

                $installment->forceFill([
                    'paid_at' => $today->copy(),
                    'paid_amount' => CardPurchasePlanner::format($quote['discounted_cents']),
                ])->save();
            }
        }
    }

    /**
     * Orçamentos calibrados pelo gasto real do mês atual (transações + cartão),
     * para a demonstração mostrar um de cada situação:
     *  Alimentação -> estourado, Transporte -> alerta (~88%), Moradia -> alerta (~96%), Lazer -> ok (~55%).
     */
    private function seedBudgets(User $user, array $categories, array $transactionSpent): void
    {
        $monthStart = Carbon::today()->startOfMonth();

        $cardSpent = [];
        foreach (app(CardSpending::class)->byCategory($user, $monthStart, $monthStart->copy()->endOfMonth()) as $row) {
            if ($row['category_id'] !== null) {
                $cardSpent[$row['category_id']] = $row['cents'] / 100;
            }
        }

        $spent = fn (string $name) => ($transactionSpent[$name] ?? 0) + ($cardSpent[$categories[$name]->id] ?? 0);

        $limits = [
            'Alimentação' => floor($spent('Alimentação') * 0.90 / 10) * 10,
            'Transporte' => ceil($spent('Transporte') / 0.88 / 10) * 10,
            'Moradia' => ceil($spent('Moradia') * 1.04 / 10) * 10,
            'Lazer' => ceil($spent('Lazer') / 0.55 / 10) * 10,
        ];

        foreach ($limits as $name => $limit) {
            $user->budgets()->create([
                'category_id' => $categories[$name]->id,
                'amount' => max(100, $limit),
            ]);
        }
    }
}
