<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Category;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'category_id' => fn (array $attributes) => Category::factory()->create(['team_id' => $attributes['team_id']]),
            'month' => '2026-10-01',
            'amount' => fake()->numberBetween(1_000, 50_000),
        ];
    }
}
