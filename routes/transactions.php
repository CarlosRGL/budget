<?php

use App\Http\Controllers\PayeeController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// Loaded inside the {current_team} group of routes/web.php.

Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
Route::post('transactions/approve', [TransactionController::class, 'approve'])->name('transactions.approve');
Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
Route::patch('transactions/{transaction}/cleared', [TransactionController::class, 'cleared'])->name('transactions.cleared');

Route::get('payees', [PayeeController::class, 'index'])->name('payees.index');
Route::post('payees/merge', [PayeeController::class, 'mergeMany'])->name('payees.merge');
Route::patch('payees/{payee}', [PayeeController::class, 'update'])->name('payees.update');
Route::delete('payees/{payee}', [PayeeController::class, 'destroy'])->name('payees.destroy');
