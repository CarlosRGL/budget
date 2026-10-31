<?php

namespace App\Enums;

/**
 * Why an imported transaction is back in the inbox for a human to look at.
 */
enum ReviewReason: string
{
    /** The bank changed the amount of a transaction already approved. */
    case AmountChanged = 'amount_changed';

    /** A pending transaction the bank no longer returns. */
    case RemovedByBank = 'removed_by_bank';

    /** A transaction typed by hand looks like this imported one. */
    case PossibleDuplicate = 'possible_duplicate';
}
