<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\MatchState;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use App\Support\Scoreboard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A fixture in the shape of the website's `Match` type.
 *
 * @mixin Fixture
 */
class MatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fixture = $this->resource;
        $state = $fixture->state();
        $inPlay = in_array($state, [MatchState::LIVE, MatchState::BREAK], true);
        $startsAt = $fixture->starting_at->setTimezone((string) config('sportmonks.display_timezone'));
        $battingTeamId = $this->battingTeamId($fixture, $state);

        $home = $this->teamOf($fixture->localTeam, $fixture->local_team_id);
        $away = $this->teamOf($fixture->visitorTeam, $fixture->visitor_team_id);

        return [
            'id' => $fixture->id,
            'slug' => $fixture->slug,
            'competition' => new CompetitionRefResource($fixture->league ?? new League([
                'id' => $fixture->league_id,
                'slug' => 'league-'.$fixture->league_id,
                'name' => 'League '.$fixture->league_id,
            ])),
            'state' => $state->value,
            'home' => $this->side($fixture, $home, $state, $battingTeamId, $startsAt->format('H:i')),
            'away' => $this->side($fixture, $away, $state, $battingTeamId, $startsAt->format('H:i')),
            'summary' => $fixture->note ?: ($inPlay ? $fixture->status : null),
            'summaryTone' => $inPlay ? 'live' : 'default',
            'venue' => $fixture->venue?->name,
            'startsAt' => $fixture->starting_at->toIso8601String(),
            'stage' => $fixture->round,
            'city' => $fixture->venue?->city,
            'statusText' => $inPlay ? collect([$this->tossNote($fixture, $home, $away), $fixture->status])->filter()->implode(' · ') : null,
            'isToday' => $startsAt->isToday(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function side(Fixture $fixture, Team $team, MatchState $state, ?int $battingTeamId, string $startTime): array
    {
        $innings = Scoreboard::innings($fixture, $team->id);
        $score = Scoreboard::score($innings);
        $inPlay = in_array($state, [MatchState::LIVE, MatchState::BREAK], true);

        return [
            'team' => new TeamSummaryResource($team),
            'score' => $score,
            'overs' => Scoreboard::overs($innings),
            'note' => $score === null && $inPlay ? 'Yet to bat' : null,
            'startTime' => $state === MatchState::UPCOMING ? $startTime : null,
            'isBatting' => $state === MatchState::LIVE && $battingTeamId === $team->id,
        ];
    }

    /**
     * The side at the crease: the team of an active batter, else the team with the latest innings.
     */
    private function battingTeamId(Fixture $fixture, MatchState $state): ?int
    {
        if ($state !== MatchState::LIVE) {
            return null;
        }

        $active = collect($fixture->batting ?? [])->first(fn (array $row): bool => (bool) ($row['active'] ?? false));

        if ($active && isset($active['team_id'])) {
            return (int) $active['team_id'];
        }

        $latest = collect($fixture->runs ?? [])->sortByDesc(fn (array $row): int => Scoreboard::number($row['inning'] ?? null))->first();

        return isset($latest['team_id']) ? (int) $latest['team_id'] : null;
    }

    protected function tossNote(Fixture $fixture, Team $home, Team $away): ?string
    {
        $winner = collect([$home, $away])->first(fn (Team $team): bool => $team->id === (int) $fixture->toss_won_team_id);

        if (! $winner || ! $fixture->elected) {
            return null;
        }

        return $winner->name.' chose to '.(Str::startsWith($fixture->elected, 'bat') ? 'bat' : 'field');
    }

    protected function teamOf(?Team $team, int $id): Team
    {
        return $team ?? new Team(['id' => $id, 'slug' => 'team-'.$id, 'name' => 'Team '.$id]);
    }
}
