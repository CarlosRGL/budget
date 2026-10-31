<?php

namespace App\Enums;

/**
 * Choices of the "Affecter" menu of the budget screen.
 */
enum AutoAssignStrategy: string
{
    /** Fund what non-snoozed targets still ask for, within Ready to Assign. */
    case Underfunded = 'underfunded';

    /** Assign what was assigned last month. */
    case LastMonth = 'last_month';

    /** Assign the average of the last twelve months. */
    case Average = 'average';

    /** Set every assignment of the month to zero. */
    case Reset = 'reset';
}
