<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $team_id
 * @property int $category_group_id
 * @property string $name
 * @property int $sort
 * @property bool $hidden
 * @property string|null $note
 */
#[Fillable(['team_id', 'category_group_id', 'name', 'sort', 'hidden', 'note'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * @return BelongsTo<CategoryGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CategoryGroup::class, 'category_group_id');
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @return HasOne<Target, $this>
     */
    public function target(): HasOne
    {
        return $this->hasOne(Target::class);
    }

    /**
     * @return HasMany<TargetSnooze, $this>
     */
    public function targetSnoozes(): HasMany
    {
        return $this->hasMany(TargetSnooze::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<TransactionSplit, $this>
     */
    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
        ];
    }
}
