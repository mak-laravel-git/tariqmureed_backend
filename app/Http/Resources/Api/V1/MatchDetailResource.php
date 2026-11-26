<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\MatchState;
use App\Models\Article;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Team;
use App\Support\BallByBall;
use App\Support\MatchSheet;
use App\Support\Scoreboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The match centre payload: the `Match` fields plus everything behind the tabs of the match page.
 *
 * Overview, scorecard, commentary, teams and statistics come from SportMonks. The feed has no written commentary,
 * so each line is made from the ball's result. Related news comes from the site's own stories.
 *
 * @mixin Fixture
 */
class MatchDetailResource extends MatchResource
{
    private const COMMENTARY_LIMIT = 600;

    /** Overs a side bats in the limited-overs formats, when the feed does not say. */
    private const FORMAT_OVERS = ['T20' => 20, 'T20I' => 20, 'T10' => 10, 'ODI' => 50];

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
        $sheet = new MatchSheet($fixture, $names);
        $balls = collect($fixture->balls ?? []);

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
            'overview' => $this->overview($fixture, $sheet, $teamNames, $balls, $this->tossNote($fixture, $home, $away)),
            'innings' => $this->innings($sheet, $teamNames, $names),
            'commentary' => $this->commentary($sheet, $teamNames, $balls),
            'squads' => $this->squads($fixture, [$home, $away]),
            'stats' => $this->stats($sheet, $home, $away, $balls),
            'relatedNews' => $this->relatedNews($request, $home, $away),
        ];
    }

    /**
     * The summary tab: the chase and the batters while play is on; the result, awards and officials around it.
     *
     * @param  array<int, string>  $teamNames
     * @param  Collection<int, array<string, mixed>>  $balls
     * @return array<string, mixed>
     */
    private function overview(Fixture $fixture, MatchSheet $sheet, array $teamNames, Collection $balls, ?string $toss): array
    {
        $details = $fixture->details ?? [];
        $live = $fixture->state() === MatchState::LIVE || $fixture->state() === MatchState::BREAK;
        $runs = collect($fixture->runs ?? [])->sortBy(fn (array $row): int => Scoreboard::number($row['inning'] ?? null))->values();
        $latest = $runs->last();
        $inning = $latest ? Scoreboard::number($latest['inning'] ?? null) : 0;
        $currentRate = $live && $latest ? Scoreboard::runRate((int) ($latest['score'] ?? 0), $latest['overs'] ?? null) : null;
        $type = strtoupper((string) $fixture->type);
        $oversEach = ($details['targetOvers'] ?? null) ?: (self::FORMAT_OVERS[$type] ?? null);

        $target = $requiredRate = $projected = null;

        if ($live && $oversEach && $inning === 2 && $runs->count() === 2) {
            $target = ($details['targetRuns'] ?? null) ?: (int) ($runs->first()['score'] ?? 0) + 1;
            $needed = $target - (int) ($latest['score'] ?? 0);
            $ballsLeft = Scoreboard::balls($oversEach) - Scoreboard::balls($latest['overs'] ?? null);
            $requiredRate = $needed > 0 && $ballsLeft > 0 ? number_format($needed / $ballsLeft * 6, 2, '.', '') : null;
        }

        if ($live && $oversEach && $inning === 1 && $currentRate !== null) {
            $projected = (string) (int) round((float) $currentRate * (float) $oversEach);
        }

        $board = $sheet->boards()->last();
        $currentBalls = $board ? $balls->where('board', $board)->sortBy('id')->values() : collect();
        $partnership = $live && $currentBalls->isNotEmpty() ? BallByBall::partnership($currentBalls) : null;
        $bowling = $live ? collect($fixture->bowling ?? [])->first(fn (array $row): bool => (bool) ($row['active'] ?? false)) : null;

        return [
            'currentRunRate' => $currentRate,
            'requiredRunRate' => $requiredRate,
            'target' => $target === null ? null : (string) $target,
            'projectedScore' => $projected,
            'partnership' => $partnership ? "{$partnership['runs']} ({$partnership['balls']} balls)" : null,
            // A finished match keeps its last batters flagged as batting, so this is for play in progress only.
            'atCrease' => ! $live ? [] : collect($fixture->batting ?? [])
                ->filter(fn (array $row): bool => (bool) ($row['active'] ?? false))
                ->map(fn (array $row): array => [
                    'name' => $sheet->name($row['player_id'] ?? null),
                    'runs' => (int) ($row['score'] ?? 0),
                    'balls' => (int) ($row['ball'] ?? 0),
                    'fours' => (int) ($row['four_x'] ?? 0),
                    'sixes' => (int) ($row['six_x'] ?? 0),
                ])
                ->values()
                ->all(),
            'bowler' => $bowling ? [
                'name' => $sheet->name($bowling['player_id'] ?? null),
                'figures' => (int) ($bowling['wickets'] ?? 0).'/'.(int) ($bowling['runs'] ?? 0).' ('.($bowling['overs'] ?? 0).' ov)',
            ] : null,
            'recentBalls' => $live ? $currentBalls->slice(-12)->map(fn (array $ball): string => BallByBall::mark($ball))->values()->all() : [],
            'result' => $fixture->state() === MatchState::RESULT ? ($fixture->note ?: null) : null,
            'toss' => $toss,
            'playerOfMatch' => $this->awardWinner($details['playerOfMatch'] ?? null),
            'playerOfSeries' => $this->awardWinner($details['playerOfSeries'] ?? null),
            'officials' => $details['officials'] ?? [],
            'series' => collect([$details['stage'] ?? null, $details['season'] ?? null])->filter()->implode(' · ') ?: null,
            'performers' => $sheet->boards()
                ->map(fn (string $label): array => ['team' => $teamNames[$sheet->battingTeamId($label)] ?? 'Team', ...$sheet->performers($label)])
                ->filter(fn (array $row): bool => $row['batter'] !== null || $row['bowler'] !== null)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{id?: int, name?: string, image?: ?string}|null  $person
     * @return array{name: string, image: ?string, slug: ?string}|null
     */
    private function awardWinner(?array $person): ?array
    {
        if (empty($person['name'])) {
            return null;
        }

        return [
            'name' => $person['name'],
            'image' => $person['image'] ?? null,
            'slug' => Player::query()->whereKey($person['id'] ?? 0)->value('slug'),
        ];
    }

    /**
     * The scorecard, one block per innings in playing order.
     *
     * @param  array<int, string>  $teamNames
     * @param  Collection<int, string>  $names
     * @return array<int, array<string, mixed>>
     */
    private function innings(MatchSheet $sheet, array $teamNames, Collection $names): array
    {
        $seen = [];

        return $sheet->boards()
            ->map(function (string $board) use ($sheet, $teamNames, $names, &$seen): array {
                $teamId = $sheet->battingTeamId($board);
                $seen[$teamId] = ($seen[$teamId] ?? 0) + 1;

                return [
                    'team' => $teamNames[$teamId] ?? 'Team '.$teamId,
                    // 2 for a side's second innings of a first-class match.
                    'number' => $seen[$teamId],
                    'rows' => $sheet->battingRows($board)->map(fn (array $row): array => $this->scorecardRow($row, $names))->all(),
                    'total' => $sheet->total($board),
                    'extras' => $sheet->extras($board),
                    'bowling' => $sheet->bowlingFigures($board),
                    'fallOfWickets' => $sheet->fallOfWickets($board),
                    'yetToBat' => $sheet->yetToBat($board),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Every delivery, the latest first.
     *
     * @param  array<int, string>  $teamNames
     * @param  Collection<int, array<string, mixed>>  $balls
     * @return array<int, array<string, mixed>>
     */
    private function commentary(MatchSheet $sheet, array $teamNames, Collection $balls): array
    {
        return $balls
            ->sortBy([
                fn (array $a, array $b): int => Scoreboard::number($b['board']) <=> Scoreboard::number($a['board']),
                fn (array $a, array $b): int => $b['id'] <=> $a['id'],
            ])
            ->take(self::COMMENTARY_LIMIT)
            ->map(fn (array $ball): array => [
                'id' => $ball['id'],
                'innings' => ($teamNames[$ball['team']] ?? 'Team').' innings',
                'over' => $ball['over'],
                'outcome' => BallByBall::mark($ball),
                'text' => BallByBall::sentence($ball),
            ])
            ->values()
            ->all();
    }

    /**
     * The newest stories that mention either side, those naming both first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function relatedNews(Request $request, Team $home, Team $away): array
    {
        $mentions = function (Article $article, Team $team): bool {
            return str_contains(mb_strtolower($article->title.' '.$article->summary.' '.$article->tags), mb_strtolower($team->name));
        };

        $stories = Article::query()
            ->visible()
            ->with(['category', 'writer'])
            ->where(function (Builder $query) use ($home, $away): void {
                foreach ([$home->name, $away->name] as $name) {
                    foreach (['title', 'summary', 'tags'] as $column) {
                        $query->orWhere($column, 'like', '%'.$name.'%');
                    }
                }
            })
            ->latest('published_at')
            ->limit(40)
            ->get()
            ->sortByDesc(fn (Article $article): int => (int) ($mentions($article, $home) && $mentions($article, $away)))
            ->take(6)
            ->values();

        return ArticleResource::collection($stories)->resolve($request);
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
     * Side-by-side numbers of the two teams; empty until one of them has batted.
     *
     * @param  Collection<int, array<string, mixed>>  $balls
     * @return array<int, array{label: string, home: ?string, away: ?string}>
     */
    private function stats(MatchSheet $sheet, Team $home, Team $away, Collection $balls): array
    {
        [$h, $a] = [$sheet->teamNumbers($home, $balls), $sheet->teamNumbers($away, $balls)];

        if ($h['runs'] === null && $a['runs'] === null) {
            return [];
        }

        $labels = [
            'runs' => 'Runs',
            'wickets' => 'Wickets lost',
            'overs' => 'Overs faced',
            'runRate' => 'Run rate',
            'fours' => 'Fours',
            'sixes' => 'Sixes',
            'boundaryRuns' => 'Runs in boundaries',
            'dotBalls' => 'Dot balls faced',
            'extras' => 'Extras received',
            'topScore' => 'Top score',
            'bestBowling' => 'Best bowling',
        ];

        return collect($labels)
            ->map(fn (string $label, string $key): array => ['label' => $label, 'home' => $h[$key], 'away' => $a[$key]])
            // A row neither side has a figure for says nothing.
            ->filter(fn (array $row): bool => $row['home'] !== null || $row['away'] !== null)
            ->values()
            ->all();
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
