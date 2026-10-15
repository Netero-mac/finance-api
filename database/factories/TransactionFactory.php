<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // A categoria é criada para o mesmo usuário, e o tipo acompanha a categoria.
            'category_id' => fn (array $attributes) => Category::factory()
                ->create(['user_id' => $attributes['user_id']])
                ->id,
            'description' => fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 1, 5000),
            'type' => fn (array $attributes) => Category::find($attributes['category_id'])->type->value,
            'date' => fake()->dateTimeBetween('-3 months')->format('Y-m-d'),
        ];
    }
}
