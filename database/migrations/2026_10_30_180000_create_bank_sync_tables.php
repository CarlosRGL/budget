<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bank sync through EnableBanking: one connection per bank consent, its bank
     * accounts mapped to household accounts, and import fields on transactions.
     */
    public function up(): void
    {
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('aspsp_name');
            $table->string('aspsp_country', 2);
            $table->string('session_id')->nullable();
            $table->string('auth_state')->nullable()->unique();
            $table->timestamp('valid_until')->nullable();
            $table->string('status');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_connection_id')->constrained()->cascadeOnDelete();
            $table->string('account_uid')->nullable();
            $table->string('identification_hash')->nullable();
            $table->string('iban_masked')->nullable();
            $table->string('name');
            $table->string('currency', 3)->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('last_balance')->nullable();
            $table->timestamp('last_balance_at')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->after('bank_account_id');
            $table->string('fingerprint', 64)->nullable()->after('external_id');
            $table->string('bank_status')->nullable()->after('fingerprint');
            $table->string('raw_label')->nullable()->after('bank_status');
            $table->string('card_suffix', 8)->nullable()->after('raw_label');
            $table->string('review_reason')->nullable()->after('card_suffix');

            $table->index(['account_id', 'external_id']);
            $table->index(['account_id', 'fingerprint']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'external_id']);
            $table->dropIndex(['account_id', 'fingerprint']);
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn(['external_id', 'fingerprint', 'bank_status', 'raw_label', 'card_suffix', 'review_reason']);
        });

        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('bank_connections');
    }
};
