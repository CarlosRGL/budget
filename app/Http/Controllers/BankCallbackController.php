<?php

namespace App\Http\Controllers;

use App\Banking\BankConnector;
use App\Banking\EnableBankingException;
use App\Enums\BankConnectionStatus;
use App\Jobs\SyncBankConnection;
use App\Models\BankConnection;
use App\Models\Scopes\CurrentTeamScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Where the bank sends the member back (the redirect URL registered at
 * EnableBanking). The `state` must be the one of a connection this member started.
 */
class BankCallbackController extends Controller
{
    public function __invoke(Request $request, BankConnector $connector): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $connection = BankConnection::withoutGlobalScope(CurrentTeamScope::class)
            ->with('team')
            ->where('user_id', $user->id)
            ->where('auth_state', (string) $request->query('state'))
            ->whereNotNull('auth_state')
            ->first();

        if (! $connection) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This return from the bank does not match any pending connection.')]);

            return to_route('home');
        }

        /** @var Team $team */
        $team = $connection->team;
        $code = $request->query('code');

        if (! is_string($code) || $code === '' || $request->filled('error')) {
            return $this->abandon($connection, $team, (string) ($request->query('error_description') ?? $request->query('error') ?? __('access denied')));
        }

        try {
            $added = $connector->complete($connection, $code, $request);
        } catch (EnableBankingException $exception) {
            return $this->abandon($connection, $team, $exception->getMessage());
        }

        if ($added > 0) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':bank is connected: now link its accounts.', ['bank' => $connection->aspsp_name])]);

            return to_route('bank-connections.accounts', ['current_team' => $team, 'bank_connection' => $connection]);
        }

        SyncBankConnection::dispatch($connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access to :bank renewed.', ['bank' => $connection->aspsp_name])]);

        return to_route('bank-connections.index', ['current_team' => $team]);
    }

    private function abandon(BankConnection $connection, Team $team, string $reason): RedirectResponse
    {
        if ($connection->status === BankConnectionStatus::Pending) {
            $connection->delete();
        } else {
            $connection->update(['auth_state' => null]);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => __('The bank did not grant access: :reason', ['reason' => $reason])]);

        return to_route('bank-connections.index', ['current_team' => $team]);
    }
}
