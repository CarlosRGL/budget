<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payees left empty when their transactions move to another one are now
 * deleted right away: clean up those already left behind.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('payees')
            ->whereNull('transfer_account_id')
            ->whereNotExists(fn (Builder $query) => $query->from('transactions')->whereColumn('transactions.payee_id', 'payees.id'))
            ->whereNotExists(fn (Builder $query) => $query->from('transaction_splits')->whereColumn('transaction_splits.payee_id', 'payees.id'))
            ->whereNotExists(fn (Builder $query) => $query->from('payee_rename_rules')->whereColumn('payee_rename_rules.payee_id', 'payees.id'))
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
