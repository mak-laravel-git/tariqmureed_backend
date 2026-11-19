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
