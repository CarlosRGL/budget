<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Team;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'account_id' => fn (array $attributes) => Account::factory()->create(['team_id' => $attributes['team_id']]),
            'date' => '2026-10-15',
            'amount' => -fake()->numberBetween(100, 20_000),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['approved_at' => now()]);
    }

    public function inflowToReadyToAssign(int $amount): static
    {
        return $this->state(fn () => ['amount' => $amount, 'inflow_to_rta' => true, 'category_id' => null]);
    }
}
