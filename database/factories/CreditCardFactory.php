<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\CreditCard>
 */
class CreditCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'credit_limit' => 5000,
            'closing_day' => 5,
            'due_day' => 15,
        ];
    }
}
