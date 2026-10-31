<?php

namespace App\Budget;

/**
 * The categories offered to a new household during the onboarding, in the
 * member's language. Each name starts with an emoji, like the budget's own.
 */
class StarterCategories
{
    /**
     * @return list<array{name: string, categories: list<string>}>
     */
    public static function groups(): array
    {
        $structure = [
            'Bills' => [
                '🏠' => 'Rent or mortgage',
                '⚡' => 'Electricity',
                '💧' => 'Water',
                '📱' => 'Internet and phone',
                '🛡️' => 'Insurance',
            ],
            'Everyday' => [
                '🛒' => 'Groceries',
                '⛽' => 'Fuel',
                '🍽️' => 'Restaurants',
                '💊' => 'Health',
                '👕' => 'Clothing',
                '🧒' => 'Kids',
            ],
            'Fun' => [
                '🎬' => 'Going out',
                '📺' => 'Subscriptions',
                '🎁' => 'Gifts',
                '🏖️' => 'Holidays',
            ],
            'Savings' => [
                '🧯' => 'Emergency fund',
                '🛋️' => 'Big purchases',
            ],
        ];

        $groups = [];

        foreach ($structure as $group => $categories) {
            $names = [];

            foreach ($categories as $emoji => $name) {
                $names[] = $emoji.' '.__($name);
            }

            $groups[] = ['name' => __($group), 'categories' => $names];
        }

        return $groups;
    }
}
