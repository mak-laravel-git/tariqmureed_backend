<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchResource;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Standing;
use App\Models\Team;
use App\Support\LeagueSchedules;
use App\Support\TeamColors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SeriesController extends Controller
{
    /**
     * Series and tournaments grouped by current / upcoming / completed.
     */
    public function index(): JsonResponse
    {
        $schedules = LeagueSchedules::all();

        $items = League::query()
            ->where('is_featured', true)
            ->whereIn('id', $schedules->keys())
            ->orderByRaw('sort_order is null')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (League $league): array => [
                'slug' => $league->slug,
                'name' => $league->name,
                'badge' => TeamColors::shortName($league->name, $league->code),
                'colors' => TeamColors::for($league->name),
                'status' => $schedules[$league->id]['status'],
                'description' => trim(($league->category ?? 'Cricket').' '.($league->format ?? '').' series'),
                'group' => $schedules[$league->id]['group'],
            ])
            ->sortBy(fn (array $item): int => ['current' => 0, 'upcoming' => 1, 'completed' => 2][$item['group']])
            ->values();

        return response()->json(['data' => [
            'groups' => [
                ['slug' => 'current', 'name' => 'Current'],
                ['slug' => 'upcoming', 'name' => 'Upcoming'],
                ['slug' => 'completed', 'name' => 'Completed'],
            ],
            'items' => $items,
        ]]);
    }

    public function show(string $slug): JsonResponse
    {
        $league = League::query()->where('slug', $slug)->firstOrFail();
        $schedule = LeagueSchedules::all([$league->id])->get($league->id);
        $relations = ['league', 'localTeam', 'visitorTeam', 'venue'];

        $standings = Standing::query()
            ->with('team')
            ->where('league_id', $league->id)
            ->when($league->current_season_id, fn (Builder $query, int $season) => $query->where('season_id', $season))
            ->orderBy('position')
            ->get();

        $teamIds = Fixture::query()->where('league_id', $league->id)->get(['local_team_id', 'visitor_team_id'])
            ->flatMap(fn (Fixture $fixture): array => [$fixture->local_team_id, $fixture->visitor_team_id])
            ->unique();

        return response()->json(['data' => [
            'slug' => $league->slug,
            'name' => $league->name,
            'abbreviation' => TeamColors::shortName($league->name, $league->code),
            'status' => $schedule['status'] ?? 'Completed',
            'dateline' => $schedule
                ? LeagueSchedules::dateRange($schedule['first'], $schedule['last']).($league->category ? " · {$league->category}" : '')
                : 'Dates to be announced',
            'overview' => "Follow every fixture, result and table update from {$league->name} on TMN Sport.",
            'upcoming' => MatchResource::collection(
                Fixture::query()->with($relations)->where('league_id', $league->id)->upcoming()->orderBy('starting_at')->limit(5)->get(),
            ),
            'matches' => MatchResource::collection(
                Fixture::query()->with($relations)->where('league_id', $league->id)->finished()->orderByDesc('starting_at')->limit(10)->get(),
            ),
            'pointsTable' => $standings->map(fn (Standing $row): array => [
                'position' => $row->position,
                'team' => $row->team?->name ?? 'Team '.$row->team_id,
                'played' => $row->played,
                'won' => $row->won,
                'lost' => $row->lost,
                'noResult' => $row->draw + $row->noresult,
                'form' => collect($row->recent_form ?? [])->map(fn (string $result): string => strtoupper(substr($result, 0, 1)))->values(),
                'points' => $row->points,
                'netRunRate' => sprintf('%+.3f', $row->net_run_rate),
            ])->values(),
            'teams' => Team::query()->whereIn('id', $teamIds)->orderBy('name')->pluck('name')->values(),
            'stats' => [],
            'news' => [],
        ]]);
    }
}
