<?php

namespace App\Enums;

enum TargetType: string
{
    /** Each month, top the category up to the amount. */
    case RefillUpTo = 'refill_up_to';

    /** Each month, add the amount on top of what is already there. */
    case SetAside = 'set_aside';

    /** Add the amount for every given weekday of the month. */
    case Weekly = 'weekly';

    /** Have the amount available by a due date. */
    case ByDate = 'by_date';

    /**
     * Get the display label for the target type.
     */
    public function label(): string
    {
        return match ($this) {
            self::RefillUpTo => __('Refill up to'),
            self::SetAside => __('Set aside another'),
            self::Weekly => __('Every week'),
            self::ByDate => __('By a date'),
        };
    }
}
