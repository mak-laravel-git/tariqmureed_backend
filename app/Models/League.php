<?php

namespace App\Models;

use Database\Factories\LeagueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A league or series from SportMonks. The primary key is the SportMonks id.
 */
class League extends Model
{
    /** @use HasFactory<LeagueFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $guarded = [];

    /**
     * The logo from the data feed, or null when there is none. Placeholder sides (such as "TBC") come with the
     * bare image host and no file, which would show as a broken picture.
     */
    public function logo(): ?string
    {
        return is_string($this->image) && preg_match('#\.(png|jpe?g|gif|webp|svg)(\?.*)?$#i', $this->image) === 1 ? $this->image : null;
    }

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Season, $this>
     */
    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    /**
     * @return HasMany<Fixture, $this>
     */
    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class);
    }

    /**
     * @return HasMany<Standing, $this>
     */
    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }
}
