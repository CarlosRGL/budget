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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        // A loan is often made of several lines (a main loan and a zero-rate
        // one, for instance), each with its own amortization.
        Schema::create('loan_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->bigInteger('principal');
            $table->unsignedInteger('rate_milli')->comment('Annual rate in thousandths of a percent: 1140 = 1.14 %');
            $table->unsignedSmallInteger('months');
            $table->date('first_payment_date');
            $table->bigInteger('payment')->nullable()->comment('Monthly payment when the bank gives another one than the computed one');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_parts');
        Schema::dropIfExists('loans');
    }
};
