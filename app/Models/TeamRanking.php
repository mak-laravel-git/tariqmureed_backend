<?php

namespace App\Models;

use Database\Factories\TeamRankingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A national team's place in the official ICC table for one format (TEST, ODI or T20I) and gender.
 */
class TeamRanking extends Model
{
    /** @use HasFactory<TeamRankingFactory> */
    use HasFactory;

    /** Formats of the official table, in the order the website shows them. */
    public const FORMATS = ['TEST' => 'Test', 'ODI' => 'ODI', 'T20I' => 'T20I'];

    protected $guarded = [];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
