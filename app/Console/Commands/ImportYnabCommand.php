<?php

namespace App\Console\Commands;

use App\Actions\Budget\CreateCategory;
use App\Actions\Budget\CreateCategoryGroup;
use App\Actions\Budget\SaveTarget;
use App\Enums\TargetType;
use App\Models\Category;
use App\Models\TargetSnooze;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import the structure of a YNAB budget: groups, categories and their targets
 * (plan, "Option — Import de la structure YNAB"). No transactions nor amounts.
 *
 * The input is the JSON of `GET /v1/budgets/{id}/categories`, from a file or
 * stdin, so the YNAB token never has to reach the server.
 */
class ImportYnabCommand extends Command
{
    protected $signature = 'budget:import-ynab
        {household : Slug of the household}
        {file=- : JSON of the YNAB categories endpoint, "-" for stdin}';

    protected $description = 'Import the groups, categories and targets of a YNAB budget';

    /**
     * YNAB groups that are not envelopes.
     */
    private const SKIPPED_GROUPS = ['Internal Master Category', 'Credit Card Payments', 'Hidden Categories'];

    public function handle(): int
    {
        $team = Team::query()->where('slug', $this->argument('household'))->first();

        if ($team === null) {
            $this->components->error("No household “{$this->argument('household')}”.");

            return self::FAILURE;
        }

        if (Category::withoutGlobalScopes()->where('team_id', $team->id)->exists()) {
            $this->components->error("“{$team->name}” already has categories: the import only fills an empty budget.");

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');
        $json = json_decode((string) file_get_contents($file === '-' ? 'php://stdin' : $file), true);
        $groups = $json['data']['category_groups'] ?? null;

        if (! is_array($groups)) {
            $this->components->error('Not the JSON of the YNAB categories endpoint.');

            return self::FAILURE;
        }

        $counts = ['groups' => 0, 'categories' => 0, 'targets' => 0];

        DB::transaction(function () use ($team, $groups, &$counts) {
            foreach ($groups as $ynabGroup) {
                $categories = array_filter((array) ($ynabGroup['categories'] ?? []), fn (array $category) => ! $category['deleted']);

                if ($ynabGroup['deleted'] || in_array($ynabGroup['name'], self::SKIPPED_GROUPS, true) || $categories === []) {
                    continue;
                }

                $group = app(CreateCategoryGroup::class)->handle($team, $this->clean($ynabGroup['name']));
                $group->update(['hidden' => (bool) $ynabGroup['hidden']]);
                $counts['groups']++;

                foreach ($categories as $ynabCategory) {
                    $category = app(CreateCategory::class)->handle($group, $this->clean($ynabCategory['name']));
                    $category->update(['hidden' => (bool) $ynabCategory['hidden']]);
                    $counts['categories']++;

                    $target = self::targetFor($ynabCategory);

                    if ($target === null) {
                        continue;
                    }

                    app(SaveTarget::class)->handle($category, ...$target);
                    $counts['targets']++;

                    if ($this->snoozedThisMonth($ynabCategory)) {
                        TargetSnooze::firstOrCreate(['category_id' => $category->id, 'month' => now()->startOfMonth()->toDateString()]);
                    }
                }
            }
        });

        $this->components->info("Imported {$counts['groups']} groups, {$counts['categories']} categories and {$counts['targets']} targets into “{$team->name}”.");

        return self::SUCCESS;
    }

    /**
     * The target closest to a YNAB goal, as SaveTarget arguments:
     * - "Refill up to" (NEED, rollover) and a balance to keep (TB), or a loan
     *   payment (DEBT): refill_up_to;
     * - "Set aside another" (NEED, whole amount) and monthly funding (MF): set_aside;
     * - a weekly NEED: weekly; a yearly NEED or a balance by a date (TBD): by_date.
     *
     * @param  array<string, mixed>  $category
     * @return array{type: TargetType, amount: int, weekday?: int|null, dueDate?: CarbonImmutable, repeat?: string|null}|null
     */
    public static function targetFor(array $category): ?array
    {
        $type = $category['goal_type'] ?? null;
        $amount = (int) round(($category['goal_target'] ?? 0) / 10);

        if ($type === null || $amount <= 0) {
            return null;
        }

        $cadence = $category['goal_cadence'] ?? null;
        $month = $category['goal_target_month'] ?? null;

        if ($type === 'TBD' || ($type === 'NEED' && $cadence === 13 && $month !== null)) {
            $due = CarbonImmutable::parse($month)->endOfMonth()->startOfDay();
            $yearly = $type === 'NEED';

            while ($yearly && $due->lt(now()->startOfMonth())) {
                $due = $due->addYearNoOverflow()->endOfMonth()->startOfDay();
            }

            return ['type' => TargetType::ByDate, 'amount' => $amount, 'dueDate' => $due, 'repeat' => $yearly ? 'yearly' : null];
        }

        if ($type === 'NEED' && $cadence === 2) {
            // YNAB counts weekdays from Sunday (0), the app from Monday (1).
            return ['type' => TargetType::Weekly, 'amount' => $amount, 'weekday' => (($category['goal_day'] ?? 6) + 6) % 7 + 1];
        }

        return match (true) {
            $type === 'MF', $type === 'NEED' && ($category['goal_needs_whole_amount'] ?? false) => ['type' => TargetType::SetAside, 'amount' => $amount],
            default => ['type' => TargetType::RefillUpTo, 'amount' => $amount],
        };
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function snoozedThisMonth(array $category): bool
    {
        $snoozedAt = $category['goal_snoozed_at'] ?? null;

        return $snoozedAt !== null && CarbonImmutable::parse($snoozedAt)->isSameMonth(now());
    }

    private function clean(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 255);
    }
}
