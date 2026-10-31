<?php

use App\Http\Controllers\BankConnectionController;
use App\Http\Controllers\PayeeRenameRuleController;
use App\Http\Controllers\TransactionReviewController;
use Illuminate\Support\Facades\Route;

// Loaded inside the {current_team} group of routes/web.php. The bank callback
// (bank/callback) lives outside it, in routes/web.php.

Route::get('bank-connections', [BankConnectionController::class, 'index'])->name('bank-connections.index');
Route::get('bank-connections/setup', [BankConnectionController::class, 'setup'])->name('bank-connections.setup');
Route::post('bank-connections', [BankConnectionController::class, 'store'])->name('bank-connections.store');
Route::delete('bank-connections/{bank_connection}', [BankConnectionController::class, 'destroy'])->name('bank-connections.destroy');
Route::post('bank-connections/{bank_connection}/renew', [BankConnectionController::class, 'renew'])->name('bank-connections.renew');
Route::post('bank-connections/{bank_connection}/sync', [BankConnectionController::class, 'sync'])->name('bank-connections.sync');
Route::get('bank-connections/{bank_connection}/accounts', [BankConnectionController::class, 'accounts'])->name('bank-connections.accounts');
Route::put('bank-connections/{bank_connection}/accounts', [BankConnectionController::class, 'saveAccounts'])->name('bank-connections.save-accounts');

Route::get('rename-rules', [PayeeRenameRuleController::class, 'index'])->name('rename-rules.index');
Route::patch('rename-rules/{payee_rename_rule}', [PayeeRenameRuleController::class, 'update'])->name('rename-rules.update');
Route::delete('rename-rules/{payee_rename_rule}', [PayeeRenameRuleController::class, 'destroy'])->name('rename-rules.destroy');
Route::post('rename-rules/{payee_rename_rule}/apply', [PayeeRenameRuleController::class, 'apply'])->name('rename-rules.apply');

Route::post('transactions/{transaction}/merge', [TransactionReviewController::class, 'merge'])->name('transactions.merge');
Route::post('transactions/{transaction}/dismiss-review', [TransactionReviewController::class, 'dismiss'])->name('transactions.dismiss-review');
