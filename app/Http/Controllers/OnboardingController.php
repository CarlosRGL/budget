<?php

namespace App\Http\Controllers;

use App\Actions\Budget\CreateStarterCategories;
use App\Banking\EnableBankingClient;
use App\Budget\StarterCategories;
use App\Http\Requests\Onboarding\StoreStarterCategoriesRequest;
use App\Models\CategoryGroup;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run setup of a household: how envelope budgeting works, a starter set
 * of categories, the accounts, then the budget.
 */
class OnboardingController extends Controller
{
    /** @var list<string> */
    public const STEPS = ['welcome', 'categories', 'accounts', 'done'];

    public function show(Request $request, Team $current_team, EnableBankingClient $client): Response|RedirectResponse
    {
        if ($current_team->isOnboarded()) {
            return to_route('budget.show', ['current_team' => $current_team]);
        }

        $step = $request->query('step');

        return Inertia::render('onboarding/show', [
            'step' => in_array($step, self::STEPS, true) ? $step : 'welcome',
            'starterGroups' => StarterCategories::groups(),
            'existingGroups' => fn () => CategoryGroup::query()
                ->with('categories:id,category_group_id,name')
                ->whereHas('categories')
                ->orderBy('sort')
                ->get()
                ->map(fn (CategoryGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'categories' => $group->categories->pluck('name')->all(),
                ])
                ->all(),
            'bankingConfigured' => $client->isConfigured(),
        ]);
    }

    /**
     * Create the starter categories the member kept.
     */
    public function storeCategories(StoreStarterCategoriesRequest $request, Team $current_team, CreateStarterCategories $createStarterCategories): RedirectResponse
    {
        if ($current_team->categories()->exists()) {
            throw ValidationException::withMessages(['groups' => __('This household already has categories.')]);
        }

        /** @var list<array{name: string, categories: list<string>}> $groups */
        $groups = $request->validated('groups');

        $createStarterCategories->handle($current_team, $groups);

        return to_route('onboarding.show', ['current_team' => $current_team, 'step' => 'accounts']);
    }

    /**
     * Finish (or skip) the setup: the household goes straight to its budget
     * from now on. The last step can also lead to the household settings.
     */
    public function complete(Request $request, Team $current_team): RedirectResponse
    {
        if (! $current_team->isOnboarded()) {
            $current_team->forceFill(['onboarded_at' => now()])->save();
        }

        return $request->input('to') === 'settings'
            ? to_route('teams.edit', ['team' => $current_team])
            : to_route('budget.show', ['current_team' => $current_team]);
    }
}
