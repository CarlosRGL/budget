<?php

namespace Database\Factories;

use App\Enums\BankConnectionStatus;
use App\Models\BankConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankConnection>
 */
class BankConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'aspsp_name' => 'Crédit Agricole',
            'aspsp_country' => 'FR',
            'session_id' => (string) Str::uuid(),
            'valid_until' => now()->addDays(90),
            'status' => BankConnectionStatus::Active,
        ];
    }

    public function pending(string $state): static
    {
        return $this->state(fn () => [
            'session_id' => null,
            'valid_until' => null,
            'auth_state' => $state,
            'status' => BankConnectionStatus::Pending,
        ]);
    }
}
