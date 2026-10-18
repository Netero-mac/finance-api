<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Budget>
 */
class BudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Categoria de despesa do mesmo usuário.
            'category_id' => fn (array $attributes) => Category::factory()
                ->create(['user_id' => $attributes['user_id'], 'type' => 'expense'])
                ->id,
            'amount' => fake()->randomFloat(2, 100, 2000),
        ];
    }
}
