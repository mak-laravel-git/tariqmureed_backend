<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\Team;
use App\Support\Scoreboard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The match centre payload: the `Match` fields plus scorecard, squads and stats.
 *
 * SportMonks does not provide commentary text or related news, so those lists stay empty.
 *
 * @mixin Fixture
 */
class MatchDetailResource extends MatchResource
{
    /**
     * How a batter was dismissed, keyed by the SportMonks score id in `wicket_id`
     * (ids from GET /scores).
     *
     * @var array<int, string>
     */
    private const WICKET_TYPES = [
        54 => 'catch', 55 => 'catch',
        56 => 'stump', 57 => 'stump', 21 => 'stump',
        58 => 'bowled', 79 => 'bowled',
        83 => 'lbw',
        20 => 'hitwicket', 87 => 'hitwicket',
        78 => 'obstructing',
        138 => 'retired',
        3 => 'runout', 5 => 'runout', 22 => 'runout', 23 => 'runout', 24 => 'runout', 25 => 'runout',
        28 => 'runout', 29 => 'runout', 32 => 'runout', 36 => 'runout', 42 => 'runout',
        63 => 'runout', 64 => 'runout', 65 => 'runout', 66 => 'runout', 67 => 'runout', 68 => 'runout',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fixture = $this->resource;
        $home = $this->teamOf($fixture->localTeam, $fixture->local_team_id);
        $away = $this->teamOf($fixture->visitorTeam, $fixture->visitor_team_id);
        $teamNames = [$home->id => $home->name, $away->id => $away->name];
        $names = $this->playerNames($fixture);

        $venue = $fixture->venue;
        $subtitle = collect([
            $fixture->round,
            collect([$venue?->name, $venue?->city])->filter()->unique()->implode(', ') ?: null,
        ])->filter()->implode(' · ');

        return [
            ...parent::toArray($request),
            'title' => "{$home->name} vs {$away->name}",
            'subtitle' => $subtitle,
            'tossNote' => $this->tossNote($fixture, $home, $away),
            'updatedAt' => $fixture->updated_at->toIso8601String(),
            'overview' => $this->overview($fixture, $names),
            'innings' => $this->innings($fixture, $teamNames, $names),
            'commentary' => [],
            'squads' => $this->squads($fixture, [$home, $away]),
            'stats' => $this->stats($fixture, $home, $away),
            'relatedNews' => [],
        ];
    }

    /**
     * @param  Collection<int, string>  $names
     * @return array<string, mixed>
     */
    private function overview(Fixture $fixture, Collection $names): array
    {
        $latest = collect($fixture->runs ?? [])
            ->sortByDesc(fn (array $row): int => Scoreboard::number($row['inning'] ?? null))
            ->first();

        return [
            'currentRunRate' => $fixture->is_live && $latest ? Scoreboard::runRate((int) ($latest['score'] ?? 0), $latest['overs'] ?? null) : null,
            'projectedScore' => null,
            'partnership' => null,
            'atCrease' => collect($fixture->batting ?? [])
                ->filter(fn (array $row): bool => (bool) ($row['active'] ?? false))
                ->map(fn (array $row): array => [
                    'name' => $names->get((int) ($row['player_id'] ?? 0), 'Player'),
                    'runs' => (int) ($row['score'] ?? 0),
                    'balls' => (int) ($row['ball'] ?? 0),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Batting scorecards, one block per innings (S1, S2, ...).
     *
     * @param  array<int, string>  $teamNames
     * @param  Collection<int, string>  $names
     * @return array<int, array<string, mixed>>
     */
    private function innings(Fixture $fixture, array $teamNames, Collection $names): array
    {
        return collect($fixture->batting ?? [])
            ->groupBy(fn (array $row): string => (string) ($row['scoreboard'] ?? 'S1'))
            ->sortBy(fn (Collection $rows, string $scoreboard): int => Scoreboard::number($scoreboard))
            ->map(function (Collection $rows) use ($teamNames, $names): array {
                $teamId = (int) ($rows->first()['team_id'] ?? 0);

                return [
                    'team' => $teamNames[$teamId] ?? 'Team '.$teamId,
                    'rows' => $rows
                        ->sortBy(fn (array $row): int => (int) ($row['sort'] ?? 0))
                        ->map(fn (array $row): array => $this->scorecardRow($row, $names))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, string>  $names
     * @return array<string, mixed>
     */
    private function scorecardRow(array $row, Collection $names): array
    {
        $runs = (int) ($row['score'] ?? 0);
        $balls = (int) ($row['ball'] ?? 0);

        return [
            'batter' => $names->get((int) ($row['player_id'] ?? 0), 'Player'),
            'dismissal' => $this->dismissal($row, $names),
            'runs' => $runs,
            'balls' => $balls,
            'fours' => (int) ($row['four_x'] ?? 0),
            'sixes' => (int) ($row['six_x'] ?? 0),
            'strikeRate' => number_format((float) ($row['rate'] ?? ($balls > 0 ? $runs / $balls * 100 : 0)), 2, '.', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, string>  $names
     */
    private function dismissal(array $row, Collection $names): string
    {
        if ($row['active'] ?? false) {
            return 'batting';
        }

        $bowler = $names->get((int) ($row['bowling_player_id'] ?? 0));
        $fielder = $names->get((int) ($row['catch_stump_player_id'] ?? 0));
        $runOutBy = $names->get((int) ($row['runout_by_id'] ?? 0));

        return match (self::WICKET_TYPES[(int) ($row['wicket_id'] ?? 0)] ?? null) {
            'catch' => $fielder !== null && $fielder !== $bowler ? trim("c {$fielder} b {$bowler}") : trim("c & b {$bowler}"),
            'stump' => trim("st {$fielder} b {$bowler}"),
            'bowled' => trim("b {$bowler}"),
            'lbw' => trim("lbw b {$bowler}"),
            'hitwicket' => trim("hit wicket b {$bowler}"),
            'runout' => $runOutBy !== null ? "run out ({$runOutBy})" : 'run out',
            'obstructing' => 'obstructing the field',
            'retired' => 'retired out',
            default => $bowler || $runOutBy || ! empty($row['batsmanout_id']) ? 'out' : 'not out',
        };
    }

    /**
     * @param  array<int, Team>  $teams
     * @return array<int, array<string, mixed>>
     */
    private function squads(Fixture $fixture, array $teams): array
    {
        $lineup = collect($fixture->lineup ?? []);
        $slugs = Player::query()->whereIn('id', $lineup->pluck('id'))->pluck('slug', 'id');

        return collect($teams)
            ->map(fn (Team $team): array => [
                'team' => new TeamSummaryResource($team),
                'players' => $lineup
                    ->filter(fn (array $player): bool => (int) data_get($player, 'lineup.team_id', 0) === $team->id)
                    ->filter(fn (array $player): bool => $this->playerName($player) !== '')
                    ->map(fn (array $player): array => [
                        'slug' => $slugs->get((int) ($player['id'] ?? 0)),
                        'name' => $this->playerName($player),
                        'image' => $player['image_path'] ?? null,
                        'role' => data_get($player, 'position.name'),
                        'captain' => (bool) data_get($player, 'lineup.captain', false),
                        'wicketkeeper' => (bool) data_get($player, 'lineup.wicketkeeper', false),
                        'substitute' => (bool) data_get($player, 'lineup.substitution', false),
                        'battingStyle' => $this->style($player['battingstyle'] ?? null),
                        'bowlingStyle' => $this->style($player['bowlingstyle'] ?? null),
                    ])
                    ->sortBy(fn (array $player): int => $player['substitute'] ? 1 : 0)
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $squad): bool => $squad['players'] !== [])
            ->values()
            ->all();
    }

    /**
     * Side-by-side totals per team, taken from the innings and batting rows.
     *
     * @return array<int, array{label: string, home: ?string, away: ?string}>
     */
    private function stats(Fixture $fixture, Team $home, Team $away): array
    {
        $batting = collect($fixture->batting ?? []);

        $perTeam = fn (Team $team): array => [
            'runs' => collect(Scoreboard::innings($fixture, $team->id))->sum('score'),
            'wickets' => collect(Scoreboard::innings($fixture, $team->id))->sum('wickets'),
            'fours' => $batting->where('team_id', $team->id)->sum('four_x'),
            'sixes' => $batting->where('team_id', $team->id)->sum('six_x'),
            'played' => collect(Scoreboard::innings($fixture, $team->id))->isNotEmpty(),
        ];

        [$h, $a] = [$perTeam($home), $perTeam($away)];

        if (! $h['played'] && ! $a['played']) {
            return [];
        }

        $row = fn (string $label, string $key): array => [
            'label' => $label,
            'home' => $h['played'] ? (string) $h[$key] : null,
            'away' => $a['played'] ? (string) $a[$key] : null,
        ];

        return [
            $row('Runs', 'runs'),
            $row('Wickets lost', 'wickets'),
            $row('Fours', 'fours'),
            $row('Sixes', 'sixes'),
        ];
    }

    /**
     * Player names by SportMonks id: lineup rows first, the players table for the rest.
     *
     * @return Collection<int, string>
     */
    private function playerNames(Fixture $fixture): Collection
    {
        $names = collect($fixture->lineup ?? [])
            ->mapWithKeys(fn (array $player): array => [(int) ($player['id'] ?? 0) => $this->playerName($player)])
            ->filter();

        $needed = collect([...($fixture->batting ?? []), ...($fixture->bowling ?? [])])
            ->flatMap(fn (array $row): array => [
                $row['player_id'] ?? null,
                $row['bowling_player_id'] ?? null,
                $row['catch_stump_player_id'] ?? null,
                $row['runout_by_id'] ?? null,
            ])
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->reject(fn (int $id): bool => $names->has($id));

        if ($needed->isEmpty()) {
            return $names;
        }

        return $names->union(Player::query()->whereIn('id', $needed)->pluck('fullname', 'id'));
    }

    /**
     * "right-hand-bat" becomes "Right hand bat".
     */
    private function style(?string $style): ?string
    {
        return filled($style) ? Str::of($style)->replace('-', ' ')->ucfirst()->toString() : null;
    }

    /**
     * @param  array<string, mixed>  $player
     */
    private function playerName(array $player): string
    {
        return trim((string) ($player['fullname'] ?? trim(($player['firstname'] ?? '').' '.($player['lastname'] ?? ''))));
    }
}
