<?php

namespace Database\Factories;

use App\Enums\TargetType;
use App\Models\Category;
use App\Models\Target;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Target>
 */
class TargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'category_id' => fn (array $attributes) => Category::factory()->create(['team_id' => $attributes['team_id']]),
            'type' => TargetType::SetAside,
            'amount' => fake()->numberBetween(1_000, 50_000),
        ];
    }
}
