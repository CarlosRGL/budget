<?php

namespace App\Models;

use Database\Factories\TransactionSplitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a split transaction. Scoped to the household through its transaction.
 *
 * @property int $id
 * @property int $transaction_id
 * @property int|null $category_id
 * @property bool $inflow_to_rta
 * @property int|null $payee_id
 * @property string|null $memo
 * @property int $amount
 */
#[Fillable(['transaction_id', 'category_id', 'inflow_to_rta', 'payee_id', 'memo', 'amount'])]
class TransactionSplit extends Model
{
    /** @use HasFactory<TransactionSplitFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Payee, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'inflow_to_rta' => 'boolean',
        ];
    }
}
