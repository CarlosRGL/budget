<?php

namespace App\Banking;

use RuntimeException;

/**
 * An error answered by the EnableBanking API, or a missing configuration.
 *
 * `errorCode` is EnableBanking's text code (e.g. `EXPIRED_SESSION`), when given.
 */
class EnableBankingException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, int $status = 0)
    {
        parent::__construct($message, $status);
    }

    /**
     * Determine if the bank consent behind the session is no longer usable.
     */
    public function isExpiredConsent(): bool
    {
        return in_array($this->errorCode, ['EXPIRED_SESSION', 'CLOSED_SESSION', 'REVOKED_SESSION', 'SESSION_DOES_NOT_EXIST'], true);
    }
}
