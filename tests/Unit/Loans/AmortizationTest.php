<?php

namespace Tests\Unit\Loans;

use App\Loans\Amortization;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Checked against a real Boursorama loan: 235 900 € at 1.14 % over 300 months,
 * and its 10 000 € zero-rate line, after the payment of 2026-10-05.
 */
class AmortizationTest extends TestCase
{
    public function test_it_matches_the_bank_figures_of_a_fixed_rate_loan(): void
    {
        $loan = new Amortization(235_900_00, 1140, 300, CarbonImmutable::parse('2021-06-05'));

        $this->assertSame(904_07, $loan->payment());
        $this->assertSame(190_331_49, $loan->balanceAt(CarbonImmutable::parse('2026-10-30')));
        $this->assertSame('2046-05-05', $loan->lastPaymentDate()->toDateString());
        $this->assertSame(0, $loan->schedule()[299]['balance']);
    }

    public function test_a_zero_rate_line_repays_the_same_capital_each_month_and_settles_at_the_end(): void
    {
        $loan = new Amortization(10_000_00, 0, 300, CarbonImmutable::parse('2021-06-05'));

        $this->assertSame(33_33, $loan->payment());
        $this->assertSame(7_833_55, $loan->balanceAt(CarbonImmutable::parse('2026-10-30')));
        $this->assertSame(34_33, $loan->schedule()[299]['payment']);
        $this->assertSame(0, array_sum(array_column($loan->schedule(), 'interest')));
    }

    public function test_the_payment_given_by_the_bank_wins_over_the_computed_one(): void
    {
        $loan = new Amortization(10_000_00, 0, 300, CarbonImmutable::parse('2021-06-05'), 40_00);

        $this->assertSame(40_00, $loan->payment());
        $this->assertCount(250, $loan->schedule());
    }

    public function test_nothing_is_repaid_before_the_first_payment(): void
    {
        $loan = new Amortization(10_000_00, 0, 300, CarbonImmutable::parse('2021-06-05'));

        $this->assertSame(10_000_00, $loan->balanceAt(CarbonImmutable::parse('2021-06-04')));
    }
}
