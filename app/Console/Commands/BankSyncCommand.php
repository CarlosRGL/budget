<?php

namespace App\Console\Commands;

use App\Jobs\SyncBankConnection;
use App\Models\BankConnection;
use App\Models\Scopes\CurrentTeamScope;
use Illuminate\Console\Command;

class BankSyncCommand extends Command
{
    protected $signature = 'bank:sync';

    protected $description = 'Queue a sync of every bank connection whose consent is still valid';

    public function handle(): int
    {
        $connections = BankConnection::withoutGlobalScope(CurrentTeamScope::class)->syncable()->get();

        $connections->each(fn (BankConnection $connection) => SyncBankConnection::dispatch($connection));

        $this->components->info("{$connections->count()} bank connection(s) queued.");

        return self::SUCCESS;
    }
}
