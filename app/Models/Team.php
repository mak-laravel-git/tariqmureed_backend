<?php

namespace App\Models;

use App\Support\TeamColors;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'national_team' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Player, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * The logo from the data feed, or null when there is none. Placeholder sides (such as "TBC") come with the
     * bare image host and no file, which would show as a broken picture.
     */
    public function logo(): ?string
    {
        return is_string($this->image) && preg_match('#\.(png|jpe?g|gif|webp|svg)(\?.*)?$#i', $this->image) === 1 ? $this->image : null;
    }

    public function shortName(): string
    {
        return TeamColors::shortName($this->name, $this->code);
    }

    /**
     * @return array{from: string, to: string}
     */
    public function colors(): array
    {
        return TeamColors::for($this->name);
    }
}
