<?php

namespace App\Models;

use Database\Factories\UniformFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Team uniform: top + ladies' dress/skirt + men's trousers.
 *
 * @property int $id
 * @property string $name
 * @property string $top
 * @property string $ladies_bottom
 * @property string $men_bottom
 * @property string|null $colors
 * @property string|null $notes
 * @property string $source
 * @property bool $is_active
 */
#[Fillable(['name', 'top', 'ladies_bottom', 'men_bottom', 'colors', 'notes', 'source', 'is_active'])]
class Uniform extends Model
{
    /** @use HasFactory<UniformFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Ministration, $this> */
    public function ministrations(): HasMany
    {
        return $this->hasMany(Ministration::class);
    }

    /** @param Builder<Uniform> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
