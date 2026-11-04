<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\CashCollection> */
class CashCollectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
            'cash' => fake()->numberBetween(1500, 9000),
            'upi' => fake()->numberBetween(1000, 7000),
            'online' => fake()->numberBetween(0, 3000),
        ];
    }
}
