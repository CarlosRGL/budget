<?php

namespace Tests\Feature\BudgetScreen;

use App\Enums\TargetType;
use App\Models\MonthNote;
use App\Models\Target;
use App\Models\TargetSnooze;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetAndNoteTest extends TestCase
{
    use InteractsWithBudget, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signIn();
    }

    public function test_a_target_is_created_then_replaced()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $url = route('budget.target.update', $this->params(['category' => $groceries]));

        $this->put($url, ['type' => 'weekly', 'amount' => 15_000, 'weekday' => 6])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $target = Target::sole();
        $this->assertSame(TargetType::Weekly, $target->type);
        $this->assertSame(6, $target->weekday);
        $this->assertSame($this->team->id, $target->team_id);

        $this->put($url, ['type' => 'by_date', 'amount' => 60_000, 'due_date' => '2027-03-15', 'repeat' => 'yearly', 'weekday' => 3])
            ->assertSessionHasNoErrors();

        $target = Target::sole();
        $this->assertSame(TargetType::ByDate, $target->type);
        $this->assertSame(60_000, $target->amount);
        $this->assertNull($target->weekday);
        $this->assertSame('2027-03-15', $target->due_date?->toDateString());
        $this->assertSame('yearly', $target->repeat);

        $this->put($url, ['type' => 'refill_up_to', 'amount' => 65_000])->assertSessionHasNoErrors();

        $target = Target::sole();
        $this->assertNull($target->due_date);
        $this->assertNull($target->repeat);
    }

    public function test_a_target_is_validated()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $url = route('budget.target.update', $this->params(['category' => $groceries]));

        $this->put($url, ['type' => 'yearly', 'amount' => 100])->assertSessionHasErrors('type');
        $this->put($url, ['type' => 'set_aside', 'amount' => 0])->assertSessionHasErrors('amount');
        $this->put($url, ['type' => 'weekly', 'amount' => 100])->assertSessionHasErrors('weekday');
        $this->put($url, ['type' => 'weekly', 'amount' => 100, 'weekday' => 8])->assertSessionHasErrors('weekday');
        $this->put($url, ['type' => 'by_date', 'amount' => 100])->assertSessionHasErrors('due_date');
        $this->put($url, ['type' => 'by_date', 'amount' => 100, 'due_date' => '2027-01-01', 'repeat' => 'monthly'])->assertSessionHasErrors('repeat');
        $this->put($url, ['type' => 'set_aside', 'amount' => 100, 'repeat' => 'yearly'])->assertSessionHasErrors('repeat');

        $this->assertSame(0, Target::count());
    }

    public function test_a_target_is_deleted()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        Target::factory()->for($this->team)->for($groceries)->create();

        $this->delete(route('budget.target.destroy', $this->params(['category' => $groceries])))
            ->assertRedirect();

        $this->assertSame(0, Target::count());
    }

    public function test_a_target_is_snoozed_for_one_month_then_resumed()
    {
        $groceries = $this->category($this->group('Quotidien'), 'Courses');
        $url = route('budget.snooze.store', $this->params(['month' => '2026-10', 'category' => $groceries]));

        $this->post($url)->assertRedirect();
        $this->post($url)->assertRedirect();

        $snooze = TargetSnooze::sole();
        $this->assertSame($groceries->id, $snooze->category_id);
        $this->assertSame('2026-10-01', $snooze->month->toDateString());

        $this->delete(route('budget.snooze.destroy', $this->params(['month' => '2026-10', 'category' => $groceries])))
            ->assertRedirect();

        $this->assertSame(0, TargetSnooze::count());
    }

    public function test_several_targets_are_paused_then_resumed_at_once()
    {
        $group = $this->group('Quotidien');
        $groceries = $this->category($group, 'Courses');
        $fuel = $this->category($group, 'Essence');
        $noTarget = $this->category($group, 'Divers');
        Target::factory()->for($this->team)->for($groceries)->create(['type' => TargetType::RefillUpTo, 'amount' => 400_00]);
        Target::factory()->for($this->team)->for($fuel)->create(['type' => TargetType::RefillUpTo, 'amount' => 150_00]);
        $url = route('budget.snooze.bulk', $this->params(['month' => '2026-10']));
        $ids = [$groceries->id, $fuel->id, $noTarget->id];

        $this->put($url, ['category_ids' => $ids, 'snoozed' => true])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$groceries->id, $fuel->id], TargetSnooze::pluck('category_id')->all());

        $this->put($url, ['category_ids' => $ids, 'snoozed' => false])->assertSessionHasNoErrors();

        $this->assertSame(0, TargetSnooze::count());
    }

    public function test_the_month_note_is_saved_then_deleted_when_emptied()
    {
        $url = route('budget.note.update', $this->params(['month' => '2026-10']));

        $this->put($url, ['note' => 'Prime de fin d’année'])->assertSessionHasNoErrors()->assertRedirect();
        $this->put($url, ['note' => 'Prime de Noël'])->assertSessionHasNoErrors();

        $note = MonthNote::sole();
        $this->assertSame('Prime de Noël', $note->note);
        $this->assertSame('2026-10-01', $note->month->toDateString());
        $this->assertSame($this->team->id, $note->team_id);

        $this->put($url, ['note' => ''])->assertSessionHasNoErrors();

        $this->assertSame(0, MonthNote::count());
    }
}
