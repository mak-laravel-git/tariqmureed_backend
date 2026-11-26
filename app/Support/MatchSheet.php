<?php

namespace App\Support;

use App\Models\Fixture;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * The innings of one match put together from the stored feed rows: totals, extras, bowling figures, fall of
 * wickets and the players still to bat. An innings is known by its scoreboard label ("S1", "S2", ...).
 */
final class MatchSheet
{
    /**
     * @param  Collection<int, string>  $names  player names by SportMonks id
     */
    public function __construct(private readonly Fixture $fixture, private readonly Collection $names) {}

    /**
     * Scoreboard labels that have anything to show, in playing order.
     *
     * @return Collection<int, string>
     */
    public function boards(): Collection
    {
        return collect($this->fixture->batting ?? [])
            ->pluck('scoreboard')
            ->merge(collect($this->fixture->scoreboards ?? [])->where('type', 'total')->pluck('scoreboard'))
            ->map(fn (mixed $board): string => (string) ($board ?: 'S1'))
            ->unique()
            ->sortBy(fn (string $board): int => Scoreboard::number($board))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function battingRows(string $board): Collection
    {
        return collect($this->fixture->batting ?? [])
            ->filter(fn (array $row): bool => (string) ($row['scoreboard'] ?? 'S1') === $board)
            ->sortBy(fn (array $row): int => (int) ($row['sort'] ?? 0))
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function bowlingRows(string $board): Collection
    {
        return collect($this->fixture->bowling ?? [])
            ->filter(fn (array $row): bool => (string) ($row['scoreboard'] ?? 'S1') === $board)
            ->sortBy(fn (array $row): int => (int) ($row['sort'] ?? 0))
            ->values();
    }

    /**
     * The team batting in this innings.
     */
    public function battingTeamId(string $board): int
    {
        $row = $this->battingRows($board)->first()
            ?? collect($this->fixture->scoreboards ?? [])->first(fn (array $entry): bool => ($entry['scoreboard'] ?? null) === $board);

        return (int) ($row['team_id'] ?? 0);
    }

    /**
     * Runs, wickets and overs of the innings; null until the feed has them.
     *
     * @return array{runs: int, wickets: int, overs: string, runRate: ?string}|null
     */
    public function total(string $board): ?array
    {
        $entry = collect($this->fixture->scoreboards ?? [])
            ->first(fn (array $row): bool => ($row['scoreboard'] ?? null) === $board && ($row['type'] ?? null) === 'total');

        if ($entry) {
            [$runs, $wickets, $overs] = [$entry['total'] ?? null, $entry['wickets'] ?? 0, $entry['overs'] ?? 0];
        } else {
            $teamId = $this->battingTeamId($board);
            $row = collect(Scoreboard::innings($this->fixture, $teamId))
                ->first(fn (array $innings): bool => Scoreboard::number($innings['inning'] ?? null) === Scoreboard::number($board));
            [$runs, $wickets, $overs] = [$row['score'] ?? null, $row['wickets'] ?? 0, $row['overs'] ?? 0];
        }

        if ($runs === null) {
            return null;
        }

        return [
            'runs' => (int) $runs,
            'wickets' => (int) $wickets,
            'overs' => (string) $overs,
            'runRate' => Scoreboard::runRate((int) $runs, $overs),
        ];
    }

    /**
     * Runs not scored off the bat. The sum comes from the total less the batters' runs; the kinds from the feed.
     *
     * @return array{total: int, wides: int, noBalls: int, byes: int, legByes: int, penalty: int}|null
     */
    public function extras(string $board): ?array
    {
        $total = $this->total($board);
        $batting = $this->battingRows($board);

        if (! $total || $batting->isEmpty()) {
            return null;
        }

        $entry = collect($this->fixture->scoreboards ?? [])
            ->first(fn (array $row): bool => ($row['scoreboard'] ?? null) === $board && ($row['type'] ?? null) === 'extra') ?? [];

        return [
            'total' => max($total['runs'] - (int) $batting->sum('score'), 0),
            'wides' => (int) ($entry['wide'] ?? 0),
            'noBalls' => (int) ($entry['noball_balls'] ?? 0) + (int) ($entry['noball_runs'] ?? 0),
            'byes' => (int) ($entry['bye'] ?? 0),
            'legByes' => (int) ($entry['leg_bye'] ?? 0),
            'penalty' => (int) ($entry['penalty'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bowlingFigures(string $board): array
    {
        return $this->bowlingRows($board)
            ->map(fn (array $row): array => [
                'bowler' => $this->name($row['player_id'] ?? null),
                'overs' => (string) ($row['overs'] ?? 0),
                'maidens' => (int) ($row['medians'] ?? 0),
                'runs' => (int) ($row['runs'] ?? 0),
                'wickets' => (int) ($row['wickets'] ?? 0),
                'economy' => number_format((float) ($row['rate'] ?? 0), 2, '.', ''),
                'wides' => (int) ($row['wide'] ?? 0),
                'noBalls' => (int) ($row['noball'] ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array{wicket: int, score: int, over: string, batter: string}>
     */
    public function fallOfWickets(string $board): array
    {
        // A batter who was not dismissed has no over against the wicket (a run out may name no bowler).
        return $this->battingRows($board)
            ->filter(fn (array $row): bool => ! ($row['active'] ?? false)
                && is_numeric($row['fow_score'] ?? null)
                && (float) ($row['fow_balls'] ?? 0) > 0)
            ->sortBy([['fow_score', 'asc'], ['fow_balls', 'asc']])
            ->values()
            ->map(fn (array $row, int $index): array => [
                'wicket' => $index + 1,
                'score' => (int) $row['fow_score'],
                'over' => (string) $row['fow_balls'],
                'batter' => $this->name($row['player_id'] ?? null),
            ])
            ->all();
    }

    /**
     * Players of the batting side named in the line-up who have no batting row yet.
     *
     * @return array<int, string>
     */
    public function yetToBat(string $board): array
    {
        $teamId = $this->battingTeamId($board);
        $batted = $this->battingRows($board)->pluck('player_id')->map(fn (mixed $id): int => (int) $id);

        if ($batted->isEmpty()) {
            return [];
        }

        return collect($this->fixture->lineup ?? [])
            ->filter(fn (array $player): bool => (int) data_get($player, 'lineup.team_id', 0) === $teamId
                && ! data_get($player, 'lineup.substitution', false)
                && ! $batted->contains((int) ($player['id'] ?? 0)))
            ->map(fn (array $player): string => $this->name($player['id'] ?? null))
            ->values()
            ->all();
    }

    /**
     * The innings' leading batter and bowler, e.g. "Mitchell Marsh 45 (30)" and "Sam Curran 3/20 (4 ov)".
     *
     * @return array{batter: ?string, bowler: ?string}
     */
    public function performers(string $board): array
    {
        $batter = $this->battingRows($board)->sortBy([['score', 'desc'], ['ball', 'asc']])->first();
        $bowler = $this->bowlingRows($board)->sortBy([['wickets', 'desc'], ['runs', 'asc']])->first();

        return [
            'batter' => $batter ? $this->name($batter['player_id'] ?? null).' '.(int) ($batter['score'] ?? 0).' ('.(int) ($batter['ball'] ?? 0).')' : null,
            'bowler' => $bowler ? $this->name($bowler['player_id'] ?? null).' '.(int) ($bowler['wickets'] ?? 0).'/'.(int) ($bowler['runs'] ?? 0).' ('.($bowler['overs'] ?? 0).' ov)' : null,
        ];
    }

    /**
     * One team's numbers over all its innings, for the side-by-side statistics.
     *
     * @param  Collection<int, array<string, mixed>>  $balls  deliveries of the whole match
     * @return array<string, ?string>
     */
    public function teamNumbers(Team $team, Collection $balls): array
    {
        $innings = collect(Scoreboard::innings($this->fixture, $team->id));
        $batting = collect($this->fixture->batting ?? [])->where('team_id', $team->id);
        $bowling = collect($this->fixture->bowling ?? [])->where('team_id', $team->id);
        $faced = $balls->where('team', $team->id);
        $played = $innings->isNotEmpty();
        $runs = (int) $innings->sum('score');
        $legalBalls = (int) $innings->sum(fn (array $row): int => Scoreboard::balls($row['overs'] ?? null));
        $top = $batting->sortBy([['score', 'desc'], ['ball', 'asc']])->first();
        $best = $bowling->sortBy([['wickets', 'desc'], ['runs', 'asc']])->first();
        $fours = (int) $batting->sum('four_x');
        $sixes = (int) $batting->sum('six_x');
        $when = fn (bool $known, string|int|null $value): ?string => $known && $value !== null ? (string) $value : null;

        return [
            'runs' => $when($played, $runs),
            'wickets' => $when($played, (int) $innings->sum('wickets')),
            'overs' => $when($played && $legalBalls > 0, intdiv($legalBalls, 6).($legalBalls % 6 ? '.'.($legalBalls % 6) : '')),
            'runRate' => $when($played && $legalBalls > 0, number_format($runs / max($legalBalls, 1) * 6, 2, '.', '')),
            'fours' => $when($played, $fours),
            'sixes' => $when($played, $sixes),
            'boundaryRuns' => $when($played && $batting->isNotEmpty(), $fours * 4 + $sixes * 6),
            'dotBalls' => $when($faced->isNotEmpty(), BallByBall::dotBalls($faced)),
            'extras' => $when($played && $batting->isNotEmpty(), max($runs - (int) $batting->sum('score'), 0)),
            'topScore' => $top ? $this->name($top['player_id'] ?? null).' '.(int) ($top['score'] ?? 0) : null,
            'bestBowling' => $best ? $this->name($best['player_id'] ?? null).' '.(int) ($best['wickets'] ?? 0).'/'.(int) ($best['runs'] ?? 0) : null,
        ];
    }

    public function name(mixed $playerId): string
    {
        return $this->names->get((int) $playerId, 'Player');
    }
}
