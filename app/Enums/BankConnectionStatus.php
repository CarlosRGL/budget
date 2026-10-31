<?php

namespace App\Enums;

enum BankConnectionStatus: string
{
    /** Waiting for the member to come back from the bank. */
    case Pending = 'pending';

    case Active = 'active';

    /** The bank consent ended: the connection must be renewed. */
    case Expired = 'expired';
}
