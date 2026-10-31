<?php

namespace App\Support;

use App\Models\User;

/**
 * A fresh install has no member yet: the first person to register opens it
 * without an invitation and becomes the owner of a new household. From then
 * on, registration is invite-only.
 */
class FirstMember
{
    public static function isExpected(): bool
    {
        return User::query()->doesntExist();
    }
}
