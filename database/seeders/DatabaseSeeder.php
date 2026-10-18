<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Cria um usuário de demonstração com três meses de movimentações
     * e orçamentos em situações diferentes (ok, alerta e estourado).
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
    }
}
