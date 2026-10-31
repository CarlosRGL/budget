<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'category_group_id' => fn (array $attributes) => CategoryGroup::factory()->create(['team_id' => $attributes['team_id']]),
            'name' => fake()->unique()->word(),
        ];
    }
}
