<?php

namespace App\Console\Commands;

use App\Actions\Accounts\CreateAccount;
use App\Actions\Budget\AssignToCategory;
use App\Actions\Budget\AutoAssign;
use App\Actions\Budget\CreateCategory;
use App\Actions\Budget\CreateCategoryGroup;
use App\Actions\Budget\SaveTarget;
use App\Actions\Transactions\SaveTransaction;
use App\Enums\AccountType;
use App\Enums\AutoAssignStrategy;
use App\Enums\TargetType;
use App\Models\Account;
use App\Models\Category;
use App\Models\MonthNote;
use App\Models\Team;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\confirm;

/**
 * Fill a household with three months of realistic budget data to try the app,
 * or remove every budget data of the household with --remove.
 *
 * Everything goes through the app's own actions, so figures, payees and the
 * money move history stay consistent.
 */
class DemoCommand extends Command
{
    protected $signature = 'budget:demo
        {household? : Slug of the household (defaults to the only one)}
        {--remove : Delete every account, category and transaction of the household}
        {--force : Remove without asking for confirmation}';

    protected $description = 'Fill a household with demo budget data, or remove all its budget data';

    /** @var array<string, Category> */
    private array $categories = [];

    /** @var array<string, Account> */
    private array $accounts = [];

    private User $member;

    public function handle(): int
    {
        $team = $this->household();

        if ($team === null) {
            return self::FAILURE;
        }

        return $this->option('remove') ? $this->remove($team) : $this->fill($team);
    }

    private function household(): ?Team
    {
        $slug = $this->argument('household');
        $teams = Team::query()->when($slug, fn ($query) => $query->where('slug', $slug))->get();

        if ($teams->count() !== 1) {
            $this->components->error($slug
                ? "No household “{$slug}”."
                : 'Several households: give the slug ('.Team::query()->pluck('slug')->implode(', ').').');

            return null;
        }

        return $teams->first();
    }

    private function remove(Team $team): int
    {
        if (! $this->option('force') && ! confirm("Delete every account, category and transaction of “{$team->name}”?", default: false)) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($team) {
            Transaction::withoutGlobalScopes()->where('team_id', $team->id)->update(['transfer_pair_id' => null]);

            foreach (['transactions', 'money_moves', 'assignments', 'targets', 'month_notes', 'payee_rename_rules', 'payees', 'accounts', 'categories', 'category_groups'] as $table) {
                DB::table($table)->where('team_id', $team->id)->delete();
            }
        });

        $this->components->info("Budget data of “{$team->name}” removed.");

        return self::SUCCESS;
    }

    private function fill(Team $team): int
    {
        if (Account::withoutGlobalScopes()->where('team_id', $team->id)->exists()
            || Category::withoutGlobalScopes()->where('team_id', $team->id)->exists()) {
            $this->components->error("“{$team->name}” already has accounts or categories: run with --remove first.");

            return self::FAILURE;
        }

        $member = $team->members()->first();

        if (! $member instanceof User) {
            $this->components->error("“{$team->name}” has no member.");

            return self::FAILURE;
        }

        $this->member = $member;
        $current = CarbonImmutable::now()->startOfMonth();
        $months = [$current->subMonths(2), $current->subMonth(), $current];

        // Actions like CreateAccount work in the member's current household.
        $member->forceFill(['current_team_id' => $team->id])->save();
        mt_srand(2026);

        DB::transaction(function () use ($team, $months) {
            $this->createAccounts($months[0]);
            $this->createCategories($team);

            foreach ($months as $index => $month) {
                $this->createTransactions($month, isCurrent: $index === 2);
            }

            $this->assign($team, $months);

            MonthNote::create([
                'team_id' => $team->id,
                'month' => $months[2]->toDateString(),
                'note' => 'Anniversaire de Camille le 18 : resto prévu.',
            ]);
        });

        $this->components->info("Demo budget created in “{$team->name}”: 5 accounts, 22 categories, 3 months of transactions.");

        return self::SUCCESS;
    }

    private function createAccounts(CarbonImmutable $start): void
    {
        $create = app(CreateAccount::class);

        foreach ([
            ['Compte joint', AccountType::Checking, 650_00],
            ['Livret A', AccountType::Savings, 6_000_00],
            ['Espèces', AccountType::Cash, 80_00],
            ['Prêt immobilier', AccountType::Loan, -142_300_00],
            ['Assurance vie', AccountType::Investment, 12_450_00],
        ] as [$name, $type, $balance]) {
            $this->accounts[$name] = $create->handle($this->member, $name, $type, $type->isOnBudgetByDefault(), $balance, $start);
        }
    }

    private function createCategories(Team $team): void
    {
        $nextMarch = CarbonImmutable::now()->month >= 3
            ? CarbonImmutable::create(CarbonImmutable::now()->year + 1, 3, 15)
            : CarbonImmutable::create(CarbonImmutable::now()->year, 3, 15);
        $christmas = CarbonImmutable::create(CarbonImmutable::now()->year, 12, 15);

        $structure = [
            'Factures' => [
                '🏠 Crédit immobilier' => [TargetType::RefillUpTo, 950_00],
                '⚡ Électricité' => [TargetType::RefillUpTo, 120_00],
                '📱 Internet et mobiles' => [TargetType::RefillUpTo, 66_00],
                '💧 Eau' => [TargetType::RefillUpTo, 40_00],
                '🛡️ Assurance MAAF' => [TargetType::ByDate, 780_00, $nextMarch],
            ],
            'Dépenses courantes' => [
                '🛒 Courses' => [TargetType::RefillUpTo, 650_00],
                '⛽ Essence' => [TargetType::RefillUpTo, 180_00],
                '🍽️ Restaurants' => [TargetType::RefillUpTo, 120_00],
                '💊 Santé' => [TargetType::RefillUpTo, 50_00],
                '🧒 Enfants' => [TargetType::RefillUpTo, 250_00],
                '🐱 Animaux' => [TargetType::RefillUpTo, 40_00],
                '👕 Vêtements' => [TargetType::SetAside, 60_00],
            ],
            'Loisirs' => [
                '🎬 Sorties' => [TargetType::Weekly, 20_00],
                '📺 Abonnements' => [TargetType::RefillUpTo, 32_00],
                '🎁 Cadeaux' => [TargetType::SetAside, 50_00],
                '🏋️ Sport' => [TargetType::RefillUpTo, 30_00],
            ],
            'Épargne et projets' => [
                '🏖️ Vacances' => [TargetType::SetAside, 200_00],
                '🚗 Entretien voiture' => [TargetType::SetAside, 70_00],
                '🎄 Noël' => [TargetType::ByDate, 600_00, $christmas],
                '🧯 Imprévus' => [TargetType::SetAside, 100_00],
                '💰 Épargne assurance vie' => [TargetType::SetAside, 400_00],
                '🎓 Formation' => null,
            ],
        ];

        foreach ($structure as $groupName => $categories) {
            $group = app(CreateCategoryGroup::class)->handle($team, $groupName);

            foreach ($categories as $name => $target) {
                $category = app(CreateCategory::class)->handle($group, $name);
                $this->categories[$this->key($name)] = $category;

                if ($target !== null) {
                    [$type, $amount] = $target;
                    $dueDate = $target[2] ?? null;
                    app(SaveTarget::class)->handle(
                        $category,
                        $type,
                        $amount,
                        weekday: $type === TargetType::Weekly ? 6 : null,
                        dueDate: $dueDate,
                        repeat: $dueDate ? 'yearly' : null,
                    );
                }
            }
        }
    }

    /**
     * "🛒 Courses" → "courses".
     */
    private function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/^[^\p{L}]+/u', '', $name)));
    }

    private function createTransactions(CarbonImmutable $month, bool $isCurrent): void
    {
        $last = $isCurrent ? CarbonImmutable::now()->day : $month->daysInMonth;
        $day = fn (int $wanted) => $wanted <= $last ? $month->setDay($wanted) : null;
        $joint = $this->accounts['Compte joint'];

        // Income and fixed bills.
        $fixed = [
            [1, 'Salaire Alex', null, 2_450_00],
            [1, 'Salaire Camille', null, 1_950_00],
            [8, 'Basic-Fit', 'sport', -29_99],
            [11, 'Nounou Sophie', 'enfants', -140_00],
            [4, 'EDF', 'électricité', -118_40],
            [6, 'Free', 'internet et mobiles', -65_97],
            [9, 'Veolia Eau', 'eau', -37_82],
            [12, 'Netflix', 'abonnements', -13_49],
            [15, 'Spotify', 'abonnements', -17_99],
        ];

        foreach ($fixed as [$wanted, $payee, $category, $amount]) {
            if ($date = $day($wanted)) {
                $this->spend($joint, $date, $payee, $category, $amount);
            }
        }

        // Money leaving or moving between accounts.
        if ($date = $day(5)) {
            $this->transfer($joint, $this->accounts['Prêt immobilier'], $date, 950_00, 'crédit immobilier');
        }

        if ($date = $day(2)) {
            $this->transfer($joint, $this->accounts['Livret A'], $date, 300_00);
        }

        if ($date = $day(3)) {
            $this->transfer($joint, $this->accounts['Assurance vie'], $date, 400_00, 'épargne assurance vie');
        }

        if ($date = $day(10)) {
            $this->transfer($joint, $this->accounts['Espèces'], $date, 60_00);
        }

        // Day-to-day spending.
        $variable = [
            ['courses', ['Carrefour', 'E.Leclerc', 'Lidl', 'Biocoop'], 7, [35_00, 85_00]],
            ['essence', ['TotalEnergies', 'Intermarché Carburant'], 3, [45_00, 58_00]],
            ['restaurants', ['Pizzeria Bella', 'Le Bistrot du Port', 'Sushi Shop'], 2, [24_00, 58_00]],
            ['santé', ['Pharmacie du Centre', 'Dr Martin'], 1, [18_00, 30_00]],
            ['enfants', ['Cantine scolaire', 'Cultura'], 2, [30_00, 50_00]],
            ['animaux', ['Zooplus', 'Clinique vétérinaire'], 1, [25_00, 38_00]],
            ['sorties', ['Pathé Cinéma', 'Bowling Strike'], 2, [18_00, 42_00]],
        ];

        foreach ($variable as [$category, $payees, $count, [$min, $max]]) {
            for ($i = 0; $i < $count; $i++) {
                if ($date = $day(mt_rand(1, 28))) {
                    $this->spend($joint, $date, $payees[array_rand($payees)], $category, -mt_rand($min, $max));
                }
            }
        }

        if ($date = $day(14)) {
            $this->spend($this->accounts['Espèces'], $date, 'Boulangerie Paul', 'courses', -mt_rand(8_00, 15_00));
        }

        // A split purchase.
        if ($date = $day(20)) {
            app(SaveTransaction::class)->handle($this->member, [
                'account_id' => $joint->id,
                'date' => $date->toDateString(),
                'payee_name' => 'Amazon',
                'amount' => -94_70,
                'cleared' => true,
                'splits' => [
                    ['category_id' => $this->categories['vêtements']->id, 'amount' => -54_80, 'memo' => 'Pull'],
                    ['category_id' => $this->categories['cadeaux']->id, 'amount' => -39_90, 'memo' => 'Livre pour Léa'],
                ],
            ]);
        }

        if ($isCurrent) {
            $this->currentMonthExtras($month, $last);
        }
    }

    /**
     * The current month shows what bank imports will look like: transactions
     * waiting for approval, one without a category, and an overspent envelope.
     */
    private function currentMonthExtras(CarbonImmutable $month, int $last): void
    {
        $joint = $this->accounts['Compte joint'];
        $date = $month->setDay(max(1, $last - 1));

        foreach ([
            ['Le Bistrot du Port', 'restaurants', -96_50, 'Anniversaire de Camille'],
            ['Carrefour', 'courses', -64_32, null],
            ['Décathlon', null, -49_99, null],
        ] as [$payee, $category, $amount, $memo]) {
            $transaction = $this->spend($joint, $date, $payee, $category, $amount, $memo, cleared: false);
            $transaction->forceFill(['approved_at' => null, 'approved_by' => null])->save();
        }
    }

    private function spend(Account $account, CarbonImmutable $date, string $payee, ?string $category, int $amount, ?string $memo = null, bool $cleared = true): Transaction
    {
        return app(SaveTransaction::class)->handle($this->member, [
            'account_id' => $account->id,
            'date' => $date->toDateString(),
            'payee_name' => $payee,
            'category_id' => $category ? $this->categories[$category]->id : null,
            'inflow_to_rta' => $category === null && $amount > 0,
            'memo' => $memo,
            'amount' => $amount,
            'cleared' => $cleared,
        ]);
    }

    private function transfer(Account $from, Account $to, CarbonImmutable $date, int $amount, ?string $category = null): void
    {
        app(SaveTransaction::class)->handle($this->member, [
            'account_id' => $from->id,
            'date' => $date->toDateString(),
            'transfer_account_id' => $to->id,
            'category_id' => $category ? $this->categories[$category]->id : null,
            'amount' => -$amount,
            'cleared' => true,
        ]);
    }

    /**
     * @param  list<CarbonImmutable>  $months
     */
    private function assign(Team $team, array $months): void
    {
        $assign = app(AssignToCategory::class);

        // First month: every target by hand, plus the emergency fund from savings.
        foreach ([
            'crédit immobilier' => 950_00, 'électricité' => 120_00, 'internet et mobiles' => 66_00,
            'eau' => 40_00, 'assurance maaf' => 130_00, 'courses' => 650_00, 'essence' => 180_00,
            'restaurants' => 120_00, 'santé' => 50_00, 'vêtements' => 60_00, 'sorties' => 80_00,
            'abonnements' => 32_00, 'cadeaux' => 50_00, 'vacances' => 1_200_00,
            'entretien voiture' => 70_00, 'noël' => 300_00, 'imprévus' => 6_000_00,
            'enfants' => 250_00, 'animaux' => 40_00, 'sport' => 30_00, 'épargne assurance vie' => 400_00,
        ] as $category => $amount) {
            $assign->handle($this->member, $this->categories[$category], $months[0], $amount);
        }

        // Following months: "Financer les objectifs non atteints", like a real household.
        app(AutoAssign::class)->handle($this->member, $team, $months[1], AutoAssignStrategy::Underfunded);
        app(AutoAssign::class)->handle($this->member, $team, $months[2], AutoAssignStrategy::Underfunded);

        // Restaurants were left short this month: the birthday dinner overspends it.
        $assign->handle($this->member, $this->categories['restaurants'], $months[2], 60_00);
    }
}
