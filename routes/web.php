<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BankCallbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SimpleViewController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\KeepViewersReadOnly;
use App\Support\FirstMember;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => match (true) {
    auth()->check() => redirect()->route('dashboard'),
    FirstMember::isExpected() => redirect()->route('register'),
    default => redirect()->route('login'),
})->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class, KeepViewersReadOnly::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
        Route::post('onboarding/categories', [OnboardingController::class, 'storeCategories'])->name('onboarding.categories');
        Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

        // The simple, read-only screens of members with the viewer role.
        Route::get('apercu/{month?}', [SimpleViewController::class, 'overview'])
            ->where('month', '\d{4}-(0[1-9]|1[0-2])')->name('simple.overview');
        Route::get('depenses/{month?}', [SimpleViewController::class, 'spending'])
            ->where('month', '\d{4}-(0[1-9]|1[0-2])')->name('simple.spending');

        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('accounts/{account}/close', [AccountController::class, 'close'])->name('accounts.close');
        Route::post('accounts/{account}/reopen', [AccountController::class, 'reopen'])->name('accounts.reopen');
        Route::post('accounts/{account}/balance', [AccountController::class, 'adjustBalance'])->name('accounts.balance');

        require __DIR__.'/banking.php';

        require __DIR__.'/budget.php';

        require __DIR__.'/transactions.php';
    });

Route::get('bank/callback', BankCallbackController::class)->middleware(['auth', 'verified'])->name('bank.callback');

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
