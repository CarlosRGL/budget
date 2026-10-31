<?php

namespace App\Http\Middleware;

use App\Models\BankConnection;
use App\Models\Transaction;
use App\Support\Locales;
use App\Support\SidebarAccounts;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'locales' => Locales::SUPPORTED,
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'sidebarAccounts' => fn () => $user?->currentTeam ? SidebarAccounts::forCurrentTeam() : null,
            'inboxCount' => fn () => $user?->currentTeam ? Transaction::query()->inInbox()->count() : 0,
            'bankRenewals' => fn () => $user?->currentTeam ? BankConnection::needingRenewal()->with('user')->get()
                ->map(fn (BankConnection $connection) => [
                    'id' => $connection->id,
                    'aspspName' => $connection->aspsp_name,
                    'owner' => $connection->user->name,
                    'validUntil' => $connection->valid_until?->toIso8601String(),
                    'expired' => $connection->isExpired(),
                ])->all() : [],
        ];
    }
}
