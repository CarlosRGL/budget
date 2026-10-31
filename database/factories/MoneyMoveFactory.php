<?php

namespace Database\Factories;

use App\Enums\MoneyMoveKind;
use App\Models\MoneyMove;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MoneyMove>
 */
class MoneyMoveFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'month' => '2026-10-01',
            'amount' => fake()->numberBetween(1_000, 50_000),
            'kind' => MoneyMoveKind::Assign,
        ];
    }
}
