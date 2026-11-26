<?php

namespace App\Models;

use App\Enums\MatchState;
use Database\Factories\FixtureFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fixture extends Model
{
    /** @use HasFactory<FixtureFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starting_at' => 'datetime',
            'is_live' => 'boolean',
            'runs' => 'array',
            'batting' => 'array',
            'bowling' => 'array',
            'lineup' => 'array',
            'balls' => 'array',
            'scoreboards' => 'array',
            'details' => 'array',
            'centre_synced_at' => 'datetime',
            'details_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<League, $this>
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function localTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'local_team_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function visitorTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'visitor_team_id');
    }

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function state(): MatchState
    {
        return MatchState::fromStatus($this->status);
    }

    /**
     * Matches being played now, including innings and meal breaks.
     *
     * @param  Builder<Fixture>  $query
     */
    public function scopeInPlay(Builder $query): void
    {
        $query->whereIn('status', [...MatchState::LIVE_STATUSES, ...MatchState::BREAK_STATUSES]);
    }

    /**
     * @param  Builder<Fixture>  $query
     */
    public function scopeUpcoming(Builder $query): void
    {
        $query->whereNotIn('status', [
            ...MatchState::LIVE_STATUSES,
            ...MatchState::BREAK_STATUSES,
            ...MatchState::FINAL_STATUSES,
        ]);
    }

    /**
     * @param  Builder<Fixture>  $query
     */
    public function scopeFinished(Builder $query): void
    {
        $query->whereIn('status', MatchState::FINAL_STATUSES);
    }
}
