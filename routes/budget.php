<?php

use App\Http\Controllers\Budget\AssignmentController;
use App\Http\Controllers\Budget\AutoAssignController;
use App\Http\Controllers\Budget\BudgetController;
use App\Http\Controllers\Budget\BudgetOrderController;
use App\Http\Controllers\Budget\CategoryController;
use App\Http\Controllers\Budget\CategoryGroupController;
use App\Http\Controllers\Budget\MoneyMoveController;
use App\Http\Controllers\Budget\MonthNoteController;
use App\Http\Controllers\Budget\StatsController;
use App\Http\Controllers\Budget\TargetController;
use App\Http\Controllers\Budget\TargetSnoozeController;
use App\Http\Controllers\LoanController;
use App\Http\Middleware\EnsureHouseholdIsSetUp;
use Illuminate\Support\Facades\Route;

/*
 * Budget screen, loaded inside the `{current_team}` group of routes/web.php.
 * Months are `YYYY-MM` in URLs.
 */

$month = '\d{4}-(0[1-9]|1[0-2])';

Route::get('budget/{month?}', [BudgetController::class, 'show'])->where('month', $month)->middleware(EnsureHouseholdIsSetUp::class)->name('budget.show');

Route::get('stats', StatsController::class)->name('stats.show');

Route::put('budget/order', BudgetOrderController::class)->name('budget.order');

Route::where(['month' => $month])->group(function () {
    Route::put('budget/{month}/note', MonthNoteController::class)->name('budget.note.update');
    Route::put('budget/{month}/categories/{category}/assignment', AssignmentController::class)->name('budget.assignment.update');
    Route::post('budget/{month}/moves', MoneyMoveController::class)->name('budget.moves.store');
    Route::post('budget/{month}/auto-assign', AutoAssignController::class)->name('budget.auto-assign');
    Route::put('budget/{month}/snoozes', [TargetSnoozeController::class, 'bulk'])->name('budget.snooze.bulk');
    Route::post('budget/{month}/categories/{category}/snooze', [TargetSnoozeController::class, 'store'])->name('budget.snooze.store');
    Route::delete('budget/{month}/categories/{category}/snooze', [TargetSnoozeController::class, 'destroy'])->name('budget.snooze.destroy');
});

Route::put('budget/categories/{category}/target', [TargetController::class, 'update'])->name('budget.target.update');
Route::delete('budget/categories/{category}/target', [TargetController::class, 'destroy'])->name('budget.target.destroy');

Route::post('budget/groups', [CategoryGroupController::class, 'store'])->name('budget.groups.store');
Route::patch('budget/groups/{group}', [CategoryGroupController::class, 'update'])->name('budget.groups.update');
Route::delete('budget/groups/{group}', [CategoryGroupController::class, 'destroy'])->name('budget.groups.destroy');

Route::post('budget/groups/{group}/categories', [CategoryController::class, 'store'])->name('budget.categories.store');
Route::patch('budget/categories/{category}', [CategoryController::class, 'update'])->name('budget.categories.update');
Route::delete('budget/categories/{category}', [CategoryController::class, 'destroy'])->name('budget.categories.destroy');

Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
Route::get('loans/{loan}', [LoanController::class, 'show'])->name('loans.show');
Route::put('loans/{loan}', [LoanController::class, 'update'])->name('loans.update');
Route::delete('loans/{loan}', [LoanController::class, 'destroy'])->name('loans.destroy');
Route::post('loans/{loan}/sync-account', [LoanController::class, 'syncAccount'])->name('loans.sync-account');
