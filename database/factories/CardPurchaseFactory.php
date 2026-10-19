<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CreditCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\CardPurchase>
 */
class CardPurchaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'credit_card_id' => CreditCard::factory(),
            // Categoria de despesa do dono do cartão.
            'category_id' => fn (array $attributes) => Category::factory()
                ->create(['user_id' => CreditCard::find($attributes['credit_card_id'])->user_id, 'type' => 'expense'])
                ->id,
            'description' => fake()->sentence(3),
            'total_amount' => 100,
            'installments_count' => 1,
            'interest_rate' => 0,
            'total_with_interest' => 100,
            'purchase_date' => '2026-10-01',
        ];
    }
}
