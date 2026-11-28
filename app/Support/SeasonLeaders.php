<?php

namespace App\Support;

use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Collection;

/**
 * The home page's "Number Crunching": who leads international cricket in the current season, from the career
 * figures the data feed has given us. A season counts when it is named after the year ("2026"); domestic
 * competitions are left out. Nothing is estimated.
 *
 * @phpstan-type Total array{name: string, team: string, code: string, matches: int, innings: int, notOuts: int, runs: int, ballsFaced: int, highest: int, hundreds: int, sixes: int, wickets: int}
 */
final class SeasonLeaders
{
    /** Formats of the leaders table, in the order of its buttons. */
    private const FORMATS = ['ODI', 'T20I', 'Test'];

    private const ROWS = 6;

    /**
     * This year's figures, or last year's while the new season has none.
     *
     * @return array{highlights: array<int, array{value: string, label: string, context: string}>, batting: array<string, mixed>}
     */
    public static function get(): array
    {
        $thisYear = now()->year;

        foreach ([$thisYear, $thisYear - 1] as $year) {
            $totals = self::totals($year);

            if ($totals !== []) {
                return ['highlights' => self::highlights($totals, $year), 'batting' => self::batting($totals, $year)];
            }
        }

        return ['highlights' => [], 'batting' => ['seasonLabel' => '', 'note' => '', 'rows' => [], 'formats' => []]];
    }

    /**
     * Every player's figures of one season, added up per format.
     *
     * @return array<string, array<int, Total>> format => player id => totals
     */
    private static function totals(int $year): array
    {
        $seasons = Season::query()
            ->where('name', (string) $year)
            ->with('league:id,category')
            ->get()
            ->reject(fn (Season $season): bool => $season->league?->category === 'Domestic')
            ->pluck('id')
            ->flip();

        if ($seasons->isEmpty()) {
            return [];
        }

        $totals = [];

        // Career records are large, so players are read in batches and only the sums are kept.
        Player::query()
            ->select(['id', 'team_id', 'fullname', 'career'])
            ->with('team:id,name,code,national_team')
            ->whereNotNull('career')
            ->lazyById(1000)
            ->each(function (Player $player) use ($seasons, &$totals): void {
                foreach ($player->career ?? [] as $row) {
                    $format = self::format((string) ($row['type'] ?? ''));

                    if ($format === null || ! $seasons->has((int) ($row['season_id'] ?? 0))) {
                        continue;
                    }

                    $totals[$format][$player->id] = self::add($totals[$format][$player->id] ?? self::blank($player), $row);
                }
            });

        return $totals;
    }

    /** The site's name for a format label of the feed, or null when it is not an international format. */
    private static function format(string $type): ?string
    {
        foreach (self::FORMATS as $format) {
            if (in_array(strtoupper($type), CricketStats::FORMATS[$format], true)) {
                return $format;
            }
        }

        return null;
    }

    /**
     * @return Total
     */
    private static function blank(Player $player): array
    {
        $team = $player->team;

        return [
            'name' => $player->fullname,
            'team' => $team?->name ?? '',
            'code' => $team ? self::teamCode($team) : '—',
            'matches' => 0,
            'innings' => 0,
            'notOuts' => 0,
            'runs' => 0,
            'ballsFaced' => 0,
            'highest' => 0,
            'hundreds' => 0,
            'sixes' => 0,
            'wickets' => 0,
        ];
    }

    /** "IND", or "IND W" for a women's side, whose feed code ("IND-W") is cut off by the four-letter limit. */
    private static function teamCode(Team $team): string
    {
        $code = trim((string) $team->code);

        return str_ends_with(strtoupper($code), '-W') ? strtoupper(substr($code, 0, -2)).' W' : $team->shortName();
    }

    /**
     * @param  Total  $total
     * @param  array<string, mixed>  $row  One season and format of a player's career.
     * @return Total
     */
    private static function add(array $total, array $row): array
    {
        $figure = fn (string $key): int => (int) data_get($row, $key, 0);

        $total['matches'] += max($figure('batting.matches'), $figure('bowling.matches'));
        $total['innings'] += $figure('batting.innings');
        $total['notOuts'] += $figure('batting.not_outs');
        $total['runs'] += $figure('batting.runs_scored');
        $total['ballsFaced'] += $figure('batting.balls_faced');
        $total['highest'] = max($total['highest'], $figure('batting.highest_inning_score'));
        $total['hundreds'] += $figure('batting.hundreds');
        $total['sixes'] += $figure('batting.six_x');
        $total['wickets'] += $figure('bowling.wickets');

        return $total;
    }

    /**
     * The leading run-scorers of each format. The first format with figures is repeated at the top level.
     *
     * @param  array<string, array<int, Total>>  $totals
     * @return array<string, mixed>
     */
    private static function batting(array $totals, int $year): array
    {
        $formats = collect(self::FORMATS)
            ->map(fn (string $format): array => [
                'key' => $format,
                'seasonLabel' => "{$format} · {$year}",
                'note' => 'Internationals, from the figures received so far',
                'rows' => self::leaders(collect($totals[$format] ?? []), 'runs')
                    ->take(self::ROWS)
                    ->map(fn (array $player): array => [
                        'playerName' => $player['name'],
                        'team' => $player['code'],
                        'matches' => $player['matches'],
                        'runs' => $player['runs'],
                        'highScore' => (string) $player['highest'],
                        // Without a dismissal or a ball count there is no figure to give.
                        'average' => $player['innings'] > $player['notOuts'] ? round($player['runs'] / ($player['innings'] - $player['notOuts']), 2) : null,
                        'strikeRate' => $player['ballsFaced'] > 0 ? round($player['runs'] / $player['ballsFaced'] * 100, 2) : null,
                        'hundreds' => $player['hundreds'],
                    ])
                    ->all(),
            ])
            ->filter(fn (array $format): bool => $format['rows'] !== [])
            ->values();

        $first = $formats->first();

        return [
            'seasonLabel' => $first['seasonLabel'] ?? '',
            'note' => $first['note'] ?? '',
            'rows' => $first['rows'] ?? [],
            'formats' => $formats->all(),
        ];
    }

    /**
     * Season totals over all international formats: the most runs, wickets, sixes and hundreds.
     *
     * @param  array<string, array<int, Total>>  $totals
     * @return array<int, array{value: string, label: string, context: string}>
     */
    private static function highlights(array $totals, int $year): array
    {
        $players = [];

        foreach ($totals as $format) {
            foreach ($format as $id => $total) {
                if (! isset($players[$id])) {
                    $players[$id] = $total;

                    continue;
                }

                foreach (['runs', 'wickets', 'sixes', 'hundreds'] as $figure) {
                    $players[$id][$figure] += $total[$figure];
                }
            }
        }

        $cards = [];

        foreach (['runs' => 'runs', 'wickets' => 'wickets', 'sixes' => 'sixes', 'hundreds' => 'hundreds'] as $figure => $word) {
            $leader = self::leaders(collect($players), $figure)->first();

            if ($leader) {
                $cards[] = [
                    'value' => number_format($leader[$figure]),
                    'label' => "Most {$word} in {$year}",
                    'context' => collect([$leader['name'], $leader['team']])->filter()->implode(' · '),
                ];
            }
        }

        return $cards;
    }

    /**
     * Players with at least one of the figure, most first; level players by name.
     *
     * @param  Collection<int, Total>  $players
     * @return Collection<int, Total>
     */
    private static function leaders(Collection $players, string $figure): Collection
    {
        return $players
            ->filter(fn (array $player): bool => $player[$figure] > 0)
            ->sortBy([[$figure, 'desc'], ['name', 'asc']])
            ->values();
    }
}
