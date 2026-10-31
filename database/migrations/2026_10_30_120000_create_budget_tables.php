<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Amounts are integer cents. Budget months are dates on the 1st of the month.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->boolean('on_budget');
            $table->date('starting_date');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('category_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('hidden')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('payees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('last_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('transfer_account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('payee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('memo')->nullable();
            $table->bigInteger('amount');
            $table->boolean('cleared')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('flag')->nullable();
            $table->foreignId('transfer_pair_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->boolean('is_starting_balance')->default(false);
            $table->boolean('inflow_to_rta')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'date']);
            $table->index(['category_id', 'date']);
            $table->index(['team_id', 'date']);
        });

        Schema::create('transaction_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('inflow_to_rta')->default(false);
            $table->foreignId('payee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('memo')->nullable();
            $table->bigInteger('amount');
            $table->timestamps();

            $table->index('category_id');
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->bigInteger('amount')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'month']);
            $table->index(['team_id', 'month']);
        });

        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->bigInteger('amount');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('due_date')->nullable();
            $table->string('repeat')->nullable();
            $table->timestamps();
        });

        Schema::create('target_snoozes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->timestamps();

            $table->unique(['category_id', 'month']);
        });

        Schema::create('money_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->foreignId('from_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('to_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('kind');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'month']);
        });

        Schema::create('month_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->text('note');
            $table->timestamps();

            $table->unique(['team_id', 'month']);
        });

        Schema::create('payee_rename_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('contains');
            $table->foreignId('payee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payee_rename_rules');
        Schema::dropIfExists('month_notes');
        Schema::dropIfExists('money_moves');
        Schema::dropIfExists('target_snoozes');
        Schema::dropIfExists('targets');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('transaction_splits');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payees');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('category_groups');
        Schema::dropIfExists('accounts');
    }
};
