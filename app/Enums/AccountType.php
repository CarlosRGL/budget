<?php

namespace App\Enums;

enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case Cash = 'cash';
    case Loan = 'loan';
    case Investment = 'investment';

    /**
     * Get the display label for the account type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Checking => __('Checking'),
            self::Savings => __('Savings'),
            self::Cash => __('Cash'),
            self::Loan => __('Loan'),
            self::Investment => __('Investment'),
        };
    }

    /**
     * Determine if accounts of this type hold budgeted money by default.
     */
    public function isOnBudgetByDefault(): bool
    {
        return match ($this) {
            self::Checking, self::Savings, self::Cash => true,
            self::Loan, self::Investment => false,
        };
    }
}
