<?php

namespace App\Models;

use App\Concerns\BelongsToTeam;
use Database\Factories\CategoryGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property int $sort
 * @property bool $hidden
 */
#[Fillable(['team_id', 'name', 'sort', 'hidden'])]
class CategoryGroup extends Model
{
    /** @use HasFactory<CategoryGroupFactory> */
    use BelongsToTeam, HasFactory;

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('sort');
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
