<?php

namespace App\Models;

use App\Enums\SongCategory;
use App\Enums\SongLanguage;
use Database\Factories\SongFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $title
 * @property SongLanguage $language
 * @property SongCategory $category
 * @property string|null $artist
 * @property string|null $musical_key
 * @property int|null $tempo_bpm
 * @property string|null $time_signature
 * @property string|null $theme
 * @property string|null $lyrics
 * @property bool $is_public_domain
 * @property string|null $notes
 * @property string $source
 */
#[Fillable(['title', 'language', 'category', 'artist', 'musical_key', 'tempo_bpm', 'time_signature', 'theme', 'lyrics', 'is_public_domain', 'notes', 'source'])]
class Song extends Model
{
    /** @use HasFactory<SongFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'language' => SongLanguage::class,
            'category' => SongCategory::class,
            'tempo_bpm' => 'integer',
            'is_public_domain' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Ministration, $this> */
    public function ministrations(): BelongsToMany
    {
        return $this->belongsToMany(Ministration::class)->withPivot('position')->withTimestamps();
    }
}
