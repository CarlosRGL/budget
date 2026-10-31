<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->date('posted_through')->nullable()->after('category_id')
                ->comment('Last day whose due dates were posted to the loan account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('posted_through');
        });
    }
};
