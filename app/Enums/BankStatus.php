<?php

namespace App\Enums;

/**
 * Status of an imported transaction on the bank side.
 */
enum BankStatus: string
{
    case Pending = 'pending';
    case Booked = 'booked';
}
