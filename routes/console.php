<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// Four unattended bank reads a day at most (PSD2 limit per consent).
Schedule::command('bank:sync')->cron('0 6,11,16,21 * * *')->withoutOverlapping();

// The capital part of each loan payment, on the loan account, once due.
Schedule::command('loans:post-payments')->dailyAt('05:00')->withoutOverlapping();
