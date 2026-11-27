<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchResource;
use App\Http\Resources\Api\V1\TeamSummaryResource;
use App\Models\Fixture;
use App\Models\Team;
use App\Models\TeamRanking;
use App\Models\Venue;
use App\Support\HomeTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    /**
     * Teams that appear in the synced fixtures.
     */
    public function index(): AnonymousResourceCollection
    {
        return TeamSummaryResource::collection(Team::query()->playing()->orderBy('name')->get());
    }

    /**
     * The few teams of the home page's "Team Fixtures" section, chosen in the admin dashboard.
     */
    public function home(): AnonymousResourceCollection
    {
        return TeamSummaryResource::collection(HomeTeams::list());
    }

    /**
     * National and domestic sides grouped for the Teams page.
     */
    public function directory(): JsonResponse
    {
        $inPlay = Fixture::query()->inPlay()->get(['local_team_id', 'visitor_team_id'])
            ->flatMap(fn (Fixture $fixture): array => [$fixture->local_team_id, $fixture->visitor_team_id])
            ->unique();

        $items = Team::query()->playing()->orderBy('name')->get()->map(fn (Team $team): array => [
            'slug' => $team->slug,
            'name' => $team->name,
            'badge' => $team->shortName(),
            'colors' => $team->colors(),
            'image' => $team->image ?: null,
            'status' => $inPlay->contains($team->id) ? 'In progress' : 'Upcoming',
            'description' => $team->national_team ? 'International cricket team' : 'Domestic cricket team',
            'group' => $team->national_team ? 'international' : 'domestic',
        ]);

        return response()->json(['data' => [
            'groups' => [
                ['slug' => 'international', 'name' => 'International'],
                ['slug' => 'domestic', 'name' => 'Domestic'],
            ],
            'items' => $items,
        ]]);
    }

    public function show(string $slug): JsonResponse
    {
        $team = Team::query()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => [
            'slug' => $team->slug,
            'name' => $team->name,
            'badge' => $team->shortName(),
            'colors' => $team->colors(),
            'kind' => $team->national_team ? 'International team' : 'Domestic team',
            'membership' => $team->national_team ? 'National side' : 'Club side',
            'nickname' => $team->name,
            'image' => $team->image ?: null,
            'ranks' => [...$this->iccRanks($team), ...$this->record($team)],
            'playerRankings' => $this->playerRankings($team),
            'players' => $team->players()->orderBy('fullname')->limit(12)->get()->map(fn ($player): array => [
                'slug' => $player->slug,
                'name' => $player->fullname,
                'role' => $player->position ?? 'Player',
                'image' => $player->image ?? '',
            ]),
        ]]);
    }

    public function fixtures(string $slug): JsonResponse
    {
        $team = Team::query()->where('slug', $slug)->firstOrFail();

        $homeGroundId = Fixture::query()
            ->where('local_team_id', $team->id)
            ->whereNotNull('venue_id')
            ->selectRaw('venue_id, count(*) as total')
            ->groupBy('venue_id')
            ->orderByDesc('total')
            ->value('venue_id');

        $matches = Fixture::query()
            ->with(['league', 'localTeam', 'visitorTeam', 'venue'])
            ->where(fn (Builder $query) => $query->where('local_team_id', $team->id)->orWhere('visitor_team_id', $team->id))
            ->orderByDesc('starting_at')
            ->limit(30)
            ->get();

        return response()->json(['data' => [
            'team' => [
                ...(new TeamSummaryResource($team))->resolve(),
                'membership' => $team->national_team ? 'National side' : 'Club side',
                'captain' => '—',
                'coach' => '—',
                'homeGround' => $homeGroundId ? (Venue::query()->find($homeGroundId)?->name ?? '—') : '—',
                'titles' => '—',
            ],
            'matches' => MatchResource::collection($matches),
        ]]);
    }

    /**
     * The team's place in the official table, one entry per format (men's table first).
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function iccRanks(Team $team): array
    {
        return TeamRanking::query()
            ->where('team_id', $team->id)
            ->where('position', '>', 0)
            ->get()
            ->sortBy([['gender', 'desc'], fn (TeamRanking $a, TeamRanking $b): int => array_search($a->format, array_keys(TeamRanking::FORMATS), true) <=> array_search($b->format, array_keys(TeamRanking::FORMATS), true)])
            ->map(fn (TeamRanking $row): array => [
                'label' => (TeamRanking::FORMATS[$row->format] ?? $row->format).($row->gender === 'women' ? " (women's)" : '').' ranking',
                'value' => '#'.$row->position,
            ])
            ->values()
            ->all();
    }

    /**
     * The team's leading batters (by career runs) and bowlers (by career wickets) in each format, taken from the
     * career statistics stored for its players. Formats with no figures are left out.
     *
     * @return array{formats: array<int, array{key: string, label: string}>, batting: array<string, array<int, array<string, mixed>>>, bowling: array<string, array<int, array<string, mixed>>>}
     */
    private function playerRankings(Team $team): array
    {
        $batting = [];
        $bowling = [];

        foreach ($team->players()->whereNotNull('career')->get() as $player) {
            foreach (collect($player->career)->groupBy(fn (array $row): string => strtoupper((string) ($row['type'] ?? '')))->except('') as $format => $rows) {
                $innings = $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.innings', 0));
                $notOuts = $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.not_outs', 0));
                $runs = $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.runs_scored', 0));
                $wickets = $rows->sum(fn (array $row): int => (int) data_get($row, 'bowling.wickets', 0));
                $conceded = $rows->sum(fn (array $row): int => (int) data_get($row, 'bowling.runs', 0));
                $person = ['slug' => $player->slug, 'name' => $player->fullname, 'image' => $player->image ?? '', 'role' => $player->position ?? 'Player'];

                if ($runs > 0) {
                    $batting[$format][] = $person + [
                        'matches' => $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.matches', 0)),
                        'runs' => $runs,
                        'average' => $innings - $notOuts > 0 ? round($runs / ($innings - $notOuts), 2) : null,
                        'hundreds' => $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.hundreds', 0)),
                        'highScore' => $rows->max(fn (array $row): int => (int) data_get($row, 'batting.highest_inning_score', 0)),
                    ];
                }

                if ($wickets > 0) {
                    $bowling[$format][] = $person + [
                        'matches' => $rows->sum(fn (array $row): int => (int) data_get($row, 'bowling.matches', 0)),
                        'wickets' => $wickets,
                        'average' => round($conceded / $wickets, 2),
                        'fiveWickets' => $rows->sum(fn (array $row): int => (int) data_get($row, 'bowling.five_wickets', 0)),
                    ];
                }
            }
        }

        $rank = fn (array $rows, string $by, bool $lowerIsBetter = false): array => collect($rows)
            ->sortBy([[$by, 'desc'], ['average', $lowerIsBetter ? 'asc' : 'desc']])
            ->take(10)
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row)
            ->all();

        $formats = collect(array_keys($batting))->merge(array_keys($bowling))->unique()
            ->sortBy(fn (string $format): int => array_search($format, ['TEST', 'ODI', 'T20I', 'T20'], true) === false ? 99 : array_search($format, ['TEST', 'ODI', 'T20I', 'T20'], true))
            ->values();

        return [
            'formats' => $formats->map(fn (string $format): array => ['key' => $format, 'label' => TeamRanking::FORMATS[$format] ?? Str::headline(strtolower($format))])->all(),
            'batting' => collect($batting)->map(fn (array $rows): array => $rank($rows, 'runs'))->all(),
            'bowling' => collect($bowling)->map(fn (array $rows): array => $rank($rows, 'wickets', true))->all(),
        ];
    }

    /**
     * Matches played, won, lost and without a result, counted from the team's finished fixtures.
     * Empty until the team has a finished match on record.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function record(Team $team): array
    {
        $finished = Fixture::query()
            ->finished()
            ->where(fn (Builder $query) => $query->where('local_team_id', $team->id)->orWhere('visitor_team_id', $team->id));

        $played = (clone $finished)->count();

        if ($played === 0) {
            return [];
        }

        $won = (clone $finished)->where('winner_team_id', $team->id)->count();
        $noResult = (clone $finished)->whereNull('winner_team_id')->count();

        return [
            ['label' => 'Matches played', 'value' => (string) $played],
            ['label' => 'Won', 'value' => (string) $won],
            ['label' => 'Lost', 'value' => (string) max($played - $won - $noResult, 0)],
            ['label' => 'No result', 'value' => (string) $noResult],
        ];
    }
}
