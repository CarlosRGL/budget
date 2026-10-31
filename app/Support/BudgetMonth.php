<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Budget months travel as `YYYY-MM` in URLs and are stored on the 1st of the month.
 */
class BudgetMonth
{
    public static function fromUrl(string $month): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m', $month);

        abort_if($parsed === null || $parsed->format('Y-m') !== $month, 404);

        return $parsed;
    }

    public static function toUrl(CarbonImmutable $month): string
    {
        return $month->format('Y-m');
    }
}
