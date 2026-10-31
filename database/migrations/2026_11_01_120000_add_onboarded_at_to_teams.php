<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Households set up before the onboarding existed (they already have
     * categories or accounts) are considered onboarded.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable()->after('is_personal');
        });

        DB::table('teams')
            ->where(fn (Builder $query) => $query
                ->whereExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('categories')->whereColumn('categories.team_id', 'teams.id'))
                ->orWhereExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('accounts')->whereColumn('accounts.team_id', 'teams.id')))
            ->update(['onboarded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('onboarded_at');
        });
    }
};
