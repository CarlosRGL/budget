<?php

namespace Tests\Feature\Console;

use App\Enums\TargetType;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\Target;
use App\Models\TargetSnooze;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportYnabCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/ynab/categories.json';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00'));
    }

    public function test_it_imports_groups_categories_and_targets(): void
    {
        $team = User::factory()->create()->currentTeam;

        $this->artisan('budget:import-ynab', ['household' => $team->slug, 'file' => base_path(self::FIXTURE)])->assertSuccessful();

        $groups = CategoryGroup::withoutGlobalScopes()->where('team_id', $team->id)->orderBy('sort')->pluck('name')->all();
        $this->assertSame(['Gastos Fijos', 'Quality of Life Goals', 'Epargne'], $groups);

        $category = fn (string $name) => Category::withoutGlobalScopes()->where('team_id', $team->id)->where('name', $name)->sole();
        $target = fn (string $name) => Target::withoutGlobalScopes()->where('category_id', $category($name)->id)->first();

        $this->assertTrue($category('🚙 Crédit Duster')->hidden);
        $this->assertNull($target('IPTV'));

        $this->assertSame(TargetType::RefillUpTo, $target('🍱 Courses')->type);
        $this->assertSame(650_00, $target('🍱 Courses')->amount);
        $this->assertSame(TargetType::RefillUpTo, $target('🏡 Crédit Maison')->type);
        $this->assertSame(TargetType::SetAside, $target('🧼 Ménage')->type);
        $this->assertSame(TargetType::SetAside, $target('💴 Livret A Juliette')->type);
        $this->assertSame(12_000_00, $target('🏦 fond de secours')->amount);

        $maaf = $target('📄 MAAF');
        $this->assertSame(TargetType::ByDate, $maaf->type);
        $this->assertSame('2026-12-31', $maaf->due_date->toDateString());
        $this->assertSame('yearly', $maaf->repeat);

        $this->assertTrue(TargetSnooze::where('category_id', $category('🍻 Sorties')->id)->where('month', '2026-10-01')->exists());
    }

    public function test_it_refuses_a_budget_that_already_has_categories(): void
    {
        $team = User::factory()->create()->currentTeam;
        $this->artisan('budget:import-ynab', ['household' => $team->slug, 'file' => base_path(self::FIXTURE)])->assertSuccessful();

        $this->artisan('budget:import-ynab', ['household' => $team->slug, 'file' => base_path(self::FIXTURE)])->assertFailed();
    }
}
