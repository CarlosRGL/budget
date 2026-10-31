<?php

namespace App\Enums;

/**
 * How a payee picks the category of its new transactions.
 */
enum PayeeCategoryMode: string
{
    /** The category of its last categorized transaction. */
    case History = 'history';

    /** Always the category chosen on the payee. */
    case Fixed = 'fixed';

    /** No category: each transaction is categorized by hand. */
    case None = 'none';
}
