<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionSplit>
 */
class TransactionSplitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'amount' => -fake()->numberBetween(100, 5_000),
        ];
    }
}
