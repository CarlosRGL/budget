<?php

namespace App\Jobs;

use App\Banking\BankSynchronizer;
use App\Models\BankConnection;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Import the latest transactions and balances of a bank connection.
 *
 * Never retried: banks allow only a few unattended reads per day (PSD2).
 */
class SyncBankConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, string>  $psuHeaders  Headers describing the member, when they asked for the sync.
     */
    public function __construct(public BankConnection $bankConnection, public array $psuHeaders = []) {}

    public function uniqueId(): string
    {
        return (string) $this->bankConnection->id;
    }

    public function handle(BankSynchronizer $synchronizer): void
    {
        $synchronizer->sync($this->bankConnection, $this->psuHeaders);
    }
}
