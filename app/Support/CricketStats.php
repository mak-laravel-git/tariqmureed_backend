<?php

namespace App\Support;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\Standing;
use App\Models\TeamRanking;
use Illuminate\Support\Collection;

/**
 * The statistics and records centre, built from what the data feed has given us: the career figures of the players
 * we follow, the matches we store, the official team rankings and the league tables. Nothing is estimated.
 */
final class CricketStats
{
    /** The site's formats and the labels the feed uses for them. */
    public const FORMATS = [
        'Test' => ['TEST', 'TEST/5DAY'],
        'ODI' => ['ODI'],
        'T20I' => ['T20I'],
        'T20' => ['T20'],
    ];

    private const TOP = 10;

    /** @var Collection<int, array<string, mixed>>|null */
    private ?Collection $careers = null;

    /**
     * Leaderboards for one scope ("player", "team" or "tournament") and format (null for all four formats).
     *
     * @return array<int, array<string, mixed>>
     */
    public function tables(string $scope, ?string $format): array
    {
        return match ($scope) {
            'team' => $this->teamTables($format),
            'tournament' => $this->tournamentTables($format),
            default => $this->playerTables($format),
        };
    }

    /**
     * The best mark of each kind in each format, e.g. the highest individual score in ODIs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function records(?string $format): array
    {
        $records = [];

        foreach ($format ? [$format] : array_keys(self::FORMATS) as $name) {
            foreach ($this->recordKinds($name) as [$group, $title, $table]) {
                $row = $table[0] ?? null;

                if ($row) {
                    $records[] = [
                        'id' => strtolower($group.'-'.preg_replace('/\W+/', '-', $title).'-'.$name),
                        'title' => $title,
                        'group' => $group,
                        'holder' => $row['name'],
                        'format' => $name,
                        'detail' => $row['note'],
                        'value' => $row['value'],
                    ];
                }
            }
        }

        return $records;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: array<int, array<string, mixed>>}>
     */
    private function recordKinds(string $format): array
    {
        $players = $this->players($format);

        return [
            ['batting', 'Most career runs', $this->rank($players, fn (array $p): int => $p['runs'], fn (array $p): string => number_format($p['runs']), fn (array $p): bool => $p['runs'] > 0)],
            ['batting', 'Highest individual score', $this->rank($players, fn (array $p): int => $p['highest'], fn (array $p): string => (string) $p['highest'], fn (array $p): bool => $p['highest'] > 0)],
            ['batting', 'Most hundreds', $this->rank($players, fn (array $p): int => $p['hundreds'], fn (array $p): string => (string) $p['hundreds'], fn (array $p): bool => $p['hundreds'] > 0)],
            ['batting', 'Most sixes', $this->rank($players, fn (array $p): int => $p['sixes'], fn (array $p): string => (string) $p['sixes'], fn (array $p): bool => $p['sixes'] > 0)],
            ['bowling', 'Most career wickets', $this->rank($players, fn (array $p): int => $p['wickets'], fn (array $p): string => (string) $p['wickets'], fn (array $p): bool => $p['wickets'] > 0)],
            ['bowling', 'Most five-wicket hauls', $this->rank($players, fn (array $p): int => $p['fiveWickets'], fn (array $p): string => (string) $p['fiveWickets'], fn (array $p): bool => $p['fiveWickets'] > 0)],
            ['bowling', 'Best bowling average', $this->bowlingAverage($players)],
            ['team', 'Highest team total', $this->teamTotals($format)],
            ['team', 'Highest ranking rating', $this->ratings($format)],
            ['tournament', 'Most points in a league table', $this->standings($format, 'points')],
            ['tournament', 'Best net run rate', $this->standings($format, 'net_run_rate')],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function playerTables(?string $format): array
    {
        $players = $this->players($format);
        $label = 'PLAYER · '.($format ?? 'All formats');

        return $this->keep([
            $this->table('most-runs', $label, 'Most runs', 'Player', 'Runs', $this->rank($players, fn (array $p): int => $p['runs'], fn (array $p): string => number_format($p['runs']), fn (array $p): bool => $p['runs'] > 0)),
            $this->table('batting-average', $label, 'Highest batting average', 'Player', 'Average', $this->rank(
                $players,
                fn (array $p): float => $p['runs'] / max($p['innings'] - $p['notOuts'], 1),
                fn (array $p): string => number_format($p['runs'] / max($p['innings'] - $p['notOuts'], 1), 2, '.', ''),
                fn (array $p): bool => $p['innings'] >= 10 && $p['innings'] > $p['notOuts'],
                'Min. 10 innings',
            )),
            $this->table('strike-rate', $label, 'Best strike rate', 'Player', 'Strike rate', $this->rank(
                $players,
                fn (array $p): float => $p['runs'] / max($p['ballsFaced'], 1) * 100,
                fn (array $p): string => number_format($p['runs'] / max($p['ballsFaced'], 1) * 100, 2, '.', ''),
                fn (array $p): bool => $p['ballsFaced'] >= 250,
                'Min. 250 balls faced',
            )),
            $this->table('most-hundreds', $label, 'Most hundreds', 'Player', '100s', $this->rank($players, fn (array $p): int => $p['hundreds'], fn (array $p): string => (string) $p['hundreds'], fn (array $p): bool => $p['hundreds'] > 0)),
            $this->table('most-sixes', $label, 'Most sixes', 'Player', 'Sixes', $this->rank($players, fn (array $p): int => $p['sixes'], fn (array $p): string => (string) $p['sixes'], fn (array $p): bool => $p['sixes'] > 0)),
            $this->table('most-wickets', $label, 'Most wickets', 'Player', 'Wickets', $this->rank($players, fn (array $p): int => $p['wickets'], fn (array $p): string => (string) $p['wickets'], fn (array $p): bool => $p['wickets'] > 0)),
            $this->table('bowling-average', $label, 'Best bowling average', 'Player', 'Average', $this->bowlingAverage($players)),
            $this->table('economy', $label, 'Best economy rate', 'Player', 'Economy', $this->rank(
                $players,
                fn (array $p): float => -($p['conceded'] / max($p['ballsBowled'], 1) * 6),
                fn (array $p): string => number_format($p['conceded'] / max($p['ballsBowled'], 1) * 6, 2, '.', ''),
                fn (array $p): bool => $p['ballsBowled'] >= 300,
                'Min. 50 overs',
            )),
            $this->table('five-wickets', $label, 'Most five-wicket hauls', 'Player', '5W', $this->rank($players, fn (array $p): int => $p['fiveWickets'], fn (array $p): string => (string) $p['fiveWickets'], fn (array $p): bool => $p['fiveWickets'] > 0)),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamTables(?string $format): array
    {
        $label = 'TEAM · '.($format ?? 'All formats');

        return $this->keep([
            $this->table('team-rating', $label, 'Highest ranking rating', 'Team', 'Rating', $this->ratings($format)),
            $this->table('team-wins', $label, 'Most wins', 'Team', 'Wins', $this->teamWins($format)),
            $this->table('team-totals', $label, 'Highest team totals', 'Team', 'Total', $this->teamTotals($format)),
            $this->table('team-sixes', $label, 'Most sixes in a match', 'Team', 'Sixes', $this->teamSixes($format)),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tournamentTables(?string $format): array
    {
        $label = 'TOURNAMENT · '.($format ?? 'All formats');

        return $this->keep([
            $this->table('table-points', $label, 'Most points in a league table', 'Team', 'Points', $this->standings($format, 'points')),
            $this->table('table-wins', $label, 'Most wins in a league table', 'Team', 'Wins', $this->standings($format, 'won')),
            $this->table('table-nrr', $label, 'Best net run rate', 'Team', 'NRR', $this->standings($format, 'net_run_rate')),
        ]);
    }

    /**
     * Career totals per player over the chosen format(s).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function players(?string $format): Collection
    {
        $types = $format ? self::FORMATS[$format] : array_merge(...array_values(self::FORMATS));

        return $this->careers()
            ->map(function (array $player) use ($types): ?array {
                $rows = collect($player['career'])->filter(fn (array $row): bool => in_array(strtoupper((string) ($row['type'] ?? '')), $types, true));

                if ($rows->isEmpty()) {
                    return null;
                }

                $sum = fn (string $key): int => (int) $rows->sum(fn (array $row): float => (float) data_get($row, $key, 0));

                return [
                    'name' => $player['name'],
                    'team' => $player['team'],
                    'matches' => max($sum('batting.matches'), $sum('bowling.matches')),
                    'innings' => $sum('batting.innings'),
                    'notOuts' => $sum('batting.not_outs'),
                    'runs' => $sum('batting.runs_scored'),
                    'ballsFaced' => $sum('batting.balls_faced'),
                    'highest' => (int) $rows->max(fn (array $row): int => (int) data_get($row, 'batting.highest_inning_score', 0)),
                    'hundreds' => $sum('batting.hundreds'),
                    'sixes' => $sum('batting.six_x'),
                    'wickets' => $sum('bowling.wickets'),
                    'conceded' => $sum('bowling.runs'),
                    'ballsBowled' => (int) $rows->sum(fn (array $row): int => Scoreboard::balls(data_get($row, 'bowling.overs', 0))),
                    'fiveWickets' => $sum('bowling.five_wickets'),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Every player with career figures, read once per request.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function careers(): Collection
    {
        if ($this->careers === null) {
            $this->careers = collect();

            Player::query()
                ->whereNotNull('career')
                ->with('team:id,name')
                ->select(['id', 'team_id', 'fullname', 'career'])
                ->lazy(200)
                ->each(function (Player $player): void {
                    $this->careers->push(['name' => $player->fullname, 'team' => $player->team?->name, 'career' => $player->career ?? []]);
                });
        }

        return $this->careers;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $players
     * @return array<int, array<string, mixed>>
     */
    private function rank(Collection $players, callable $score, callable $value, callable $qualifies, ?string $note = null): array
    {
        return $players
            ->filter($qualifies)
            ->sortByDesc($score)
            ->take(self::TOP)
            ->values()
            ->map(fn (array $player, int $index): array => [
                'rank' => $index + 1,
                'name' => $player['name'],
                'note' => collect([$player['team'], $note ?? $player['matches'].' matches'])->filter()->implode(' · '),
                'value' => $value($player),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $players
     * @return array<int, array<string, mixed>>
     */
    private function bowlingAverage(Collection $players): array
    {
        return $this->rank(
            $players,
            fn (array $p): float => -($p['conceded'] / max($p['wickets'], 1)),
            fn (array $p): string => number_format($p['conceded'] / max($p['wickets'], 1), 2, '.', ''),
            fn (array $p): bool => $p['wickets'] >= 20,
            'Min. 20 wickets',
        );
    }

    /**
     * Finished matches of the format(s), with their teams and competition.
     *
     * @return Collection<int, Fixture>
     */
    private function matches(?string $format): Collection
    {
        $types = $format ? self::FORMATS[$format] : array_merge(...array_values(self::FORMATS));

        return Fixture::query()
            ->finished()
            ->with(['league:id,name', 'localTeam:id,name', 'visitorTeam:id,name'])
            ->get(['id', 'league_id', 'type', 'local_team_id', 'visitor_team_id', 'winner_team_id', 'starting_at', 'runs', 'batting'])
            ->filter(fn (Fixture $fixture): bool => in_array(strtoupper((string) $fixture->type), $types, true))
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamTotals(?string $format): array
    {
        return $this->matches($format)
            ->flatMap(function (Fixture $fixture): array {
                $teams = [$fixture->local_team_id => $fixture->localTeam, $fixture->visitor_team_id => $fixture->visitorTeam];

                return collect($fixture->runs ?? [])
                    ->map(function (array $innings) use ($fixture, $teams): ?array {
                        $team = $teams[(int) ($innings['team_id'] ?? 0)] ?? null;
                        $opponent = collect($teams)->reject(fn ($entry, $id): bool => $id === (int) ($innings['team_id'] ?? 0))->first();

                        return $team ? [
                            'name' => $team->name,
                            'note' => 'v '.($opponent?->name ?? 'opponent').' · '.($fixture->league?->name ?? 'Match').' · '.$fixture->starting_at->format('j M Y'),
                            'score' => (int) ($innings['score'] ?? 0),
                            'value' => ($innings['score'] ?? 0).'/'.($innings['wickets'] ?? 0),
                        ] : null;
                    })
                    ->filter()
                    ->all();
            })
            ->sortByDesc('score')
            ->take(self::TOP)
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1, 'name' => $row['name'], 'note' => $row['note'], 'value' => $row['value']])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamSixes(?string $format): array
    {
        return $this->matches($format)
            ->flatMap(fn (Fixture $fixture): array => collect($fixture->batting ?? [])
                ->groupBy('team_id')
                ->map(function (Collection $rows, int|string $teamId) use ($fixture): array {
                    $team = (int) $teamId === $fixture->local_team_id ? $fixture->localTeam : $fixture->visitorTeam;
                    $opponent = (int) $teamId === $fixture->local_team_id ? $fixture->visitorTeam : $fixture->localTeam;

                    return [
                        'name' => $team?->name ?? 'Team',
                        'note' => 'v '.($opponent?->name ?? 'opponent').' · '.$fixture->starting_at->format('j M Y'),
                        'sixes' => (int) $rows->sum('six_x'),
                    ];
                })
                ->values()
                ->all())
            ->filter(fn (array $row): bool => $row['sixes'] > 0)
            ->sortByDesc('sixes')
            ->take(self::TOP)
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1, 'name' => $row['name'], 'note' => $row['note'], 'value' => (string) $row['sixes']])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teamWins(?string $format): array
    {
        $matches = $this->matches($format)->filter(fn (Fixture $fixture): bool => $fixture->winner_team_id !== null);

        return $matches
            ->groupBy('winner_team_id')
            ->map(function (Collection $won, int|string $teamId) use ($matches): array {
                $first = $won->first();
                $team = (int) $teamId === $first->local_team_id ? $first->localTeam : $first->visitorTeam;
                $played = $matches->filter(fn (Fixture $fixture): bool => in_array((int) $teamId, [$fixture->local_team_id, $fixture->visitor_team_id], true))->count();

                return ['name' => $team?->name ?? 'Team', 'wins' => $won->count(), 'played' => $played];
            })
            ->sortByDesc('wins')
            ->take(self::TOP)
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1, 'name' => $row['name'], 'note' => "{$row['played']} matches on record", 'value' => (string) $row['wins']])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ratings(?string $format): array
    {
        $code = match ($format) {
            'Test' => 'TEST',
            'ODI' => 'ODI',
            'T20I' => 'T20I',
            null => null,
            default => false,
        };

        if ($code === false) {
            return [];
        }

        return TeamRanking::query()
            ->with('team:id,name')
            ->where('gender', 'men')
            ->when($code, fn ($query) => $query->where('format', $code))
            ->orderByDesc('rating')
            ->limit(self::TOP)
            ->get()
            ->values()
            ->map(fn (TeamRanking $row, int $index): array => [
                'rank' => $index + 1,
                'name' => $row->team?->name ?? 'Team',
                'note' => (TeamRanking::FORMATS[$row->format] ?? $row->format).' ranking #'.$row->position.' · '.$row->matches.' matches',
                'value' => (string) $row->rating,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function standings(?string $format, string $column): array
    {
        return Standing::query()
            ->with(['team:id,name', 'league:id,name,format'])
            ->get()
            ->filter(fn (Standing $row): bool => $format === null || $this->leagueFormat($row->league?->format) === $format)
            ->filter(fn (Standing $row): bool => $row->played > 0)
            ->sortByDesc($column)
            ->take(self::TOP)
            ->values()
            ->map(fn (Standing $row, int $index): array => [
                'rank' => $index + 1,
                'name' => $row->team?->name ?? 'Team',
                'note' => ($row->league?->name ?? 'League').' · played '.$row->played,
                'value' => $column === 'net_run_rate' ? sprintf('%+.3f', $row->net_run_rate) : (string) $row->{$column},
            ])
            ->all();
    }

    private function leagueFormat(?string $format): ?string
    {
        $upper = strtoupper((string) $format);

        foreach (self::FORMATS as $name => $types) {
            if (in_array($upper, $types, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function table(string $id, string $eyebrow, string $title, string $subject, string $valueLabel, array $rows): array
    {
        return compact('id', 'eyebrow', 'title', 'subject', 'valueLabel', 'rows');
    }

    /**
     * Leaderboards with at least one row; an empty one says nothing.
     *
     * @param  array<int, array<string, mixed>>  $tables
     * @return array<int, array<string, mixed>>
     */
    private function keep(array $tables): array
    {
        return array_values(array_filter($tables, fn (array $table): bool => $table['rows'] !== []));
    }
}
