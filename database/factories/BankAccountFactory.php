<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\BankConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_connection_id' => BankConnection::factory(),
            'team_id' => fn (array $attributes) => BankConnection::withoutGlobalScopes()->whereKey($attributes['bank_connection_id'])->value('team_id'),
            'account_uid' => (string) Str::uuid(),
            'identification_hash' => Str::random(40),
            'iban_masked' => 'FR76 •••• 0143',
            'name' => 'Compte courant',
            'currency' => 'EUR',
        ];
    }
}
