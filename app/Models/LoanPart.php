<?php

namespace App\Models;

use App\Loans\Amortization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $loan_id
 * @property string $label
 * @property int $principal Amount borrowed, in cents.
 * @property int $rate_milli Annual rate in thousandths of a percent (1140 = 1.14 %).
 * @property int $months
 * @property Carbon $first_payment_date
 * @property int|null $payment Monthly payment given by the bank, in cents; computed when null.
 */
#[Fillable(['loan_id', 'label', 'principal', 'rate_milli', 'months', 'first_payment_date', 'payment'])]
class LoanPart extends Model
{
    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function amortization(): Amortization
    {
        return new Amortization(
            $this->principal,
            $this->rate_milli,
            $this->months,
            $this->first_payment_date->toImmutable(),
            $this->payment,
        );
    }

    protected function casts(): array
    {
        return [
            'principal' => 'integer',
            'rate_milli' => 'integer',
            'months' => 'integer',
            'first_payment_date' => 'date',
            'payment' => 'integer',
        ];
    }
}
