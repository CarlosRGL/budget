<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->randomElement(['Crédit Agricole', 'Boursorama', 'LCL', 'Banque Postale']).' '.fake()->unique()->numberBetween(1, 9999),
            'type' => AccountType::Checking,
            'starting_date' => '2026-10-01',
        ];
    }

    public function offBudget(): static
    {
        return $this->state(fn () => ['type' => AccountType::Investment]);
    }
}
