<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompetitionRefResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MatchdayController extends Controller
{
    private const VIEWS = ['live', 'upcoming', 'result', 'yesterday', 'today', 'tomorrow', 'week', 'month', 'year'];

    /** Views that span many days: listed by date, a page at a time. */
    private const PERIODS = ['month', 'year'];

    private const PAGE = 60;

    private const MOST = 3000;

    /**
     * Fixtures for the Live Scores and Schedule pages, optionally of one competition and one team. The month and
     * year views group them by date; every other view groups them by competition.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'today';
        $competition = $request->string('competition')->toString();
        $team = $request->string('team')->toString();
        $timezone = (string) config('sportmonks.display_timezone');
        $today = now($timezone)->startOfDay();

        $query = Fixture::query();

        match ($view) {
            'live' => $query->inPlay(),
            'upcoming' => $query->upcoming()->where('starting_at', '>=', now()->subHours(3)),
            'result' => $query->finished()->where('starting_at', '>=', now()->subDays(14)),
            'yesterday' => $this->between($query, $today->subDay(), $today),
            'today' => $this->betweenOrInPlay($query, $today, $today->addDay()),
            'tomorrow' => $this->between($query, $today->addDay(), $today->addDays(2)),
            'week' => $this->betweenOrInPlay($query, $today, $today->addDays(7)),
            'month' => $this->betweenOrInPlay($query, $today->startOfMonth(), $today->startOfMonth()->addMonth()),
            'year' => $this->betweenOrInPlay($query, $today->startOfYear(), $today->startOfYear()->addYear()),
        };

        $filtered = $this->ofTeam($this->ofCompetition(clone $query, $competition), $team)
            ->with(['league', 'localTeam', 'visitorTeam', 'venue']);

        $data = [
            'date' => $today->toDateString(),
            // Each drop-down offers what the other one's choice leaves, from all the matches of the view.
            'competitions' => CompetitionRefResource::collection($this->competitions($this->ofTeam(clone $query, $team), $competition)),
            'teams' => $this->teams($this->ofCompetition(clone $query, $competition), $team),
        ];

        if (in_array($view, self::PERIODS, true)) {
            $limit = min(max($request->integer('limit', self::PAGE), 1), self::MOST);
            $total = (clone $filtered)->count();
            $days = $filtered->orderBy('starting_at')->orderBy('id')->limit($limit)->get()
                ->groupBy(fn (Fixture $fixture): string => $fixture->starting_at->setTimezone($timezone)->toDateString())
                ->map(fn (Collection $matches, string $date): array => ['date' => $date, 'matches' => MatchResource::collection($matches->values())])
                ->values();

            return response()->json(['data' => [...$data, 'groups' => [], 'days' => $days, 'total' => $total]]);
        }

        $groups = $filtered
            ->orderBy('starting_at', $view === 'result' ? 'desc' : 'asc')
            ->limit(300)
            ->get()
            ->groupBy('league_id')
            ->map(fn (Collection $matches): array => [
                'competition' => new CompetitionRefResource($matches->first()->league ?? new League(['slug' => 'league', 'name' => 'League'])),
                'matches' => MatchResource::collection($matches->values()),
            ])
            ->sortBy(fn (array $group): string => $group['competition']->name)
            ->values();

        return response()->json(['data' => [...$data, 'groups' => $groups]]);
    }

    /**
     * @param  Builder<Fixture>  $query
     * @return Builder<Fixture>
     */
    private function ofCompetition(Builder $query, string $slug): Builder
    {
        return $query->when($slug !== '', fn (Builder $query) => $query->whereIn('league_id', League::query()->where('slug', $slug)->select('id')));
    }

    /**
     * @param  Builder<Fixture>  $query
     * @return Builder<Fixture>
     */
    private function ofTeam(Builder $query, string $slug): Builder
    {
        return $query->when($slug !== '', function (Builder $query) use ($slug): void {
            $team = Team::query()->where('slug', $slug)->select('id');

            $query->where(fn (Builder $query) => $query
                ->whereIn('local_team_id', clone $team)
                ->orWhereIn('visitor_team_id', clone $team));
        });
    }

    /**
     * The competitions of the matches, and the chosen one even when it has none, so the drop-down can show it.
     *
     * @param  Builder<Fixture>  $matches
     * @return \Illuminate\Database\Eloquent\Collection<int, League>
     */
    private function competitions(Builder $matches, string $chosen): \Illuminate\Database\Eloquent\Collection
    {
        return League::query()
            ->whereIn('id', $matches->select('league_id'))
            ->when($chosen !== '', fn (Builder $query) => $query->orWhere('slug', $chosen))
            ->orderBy('name')
            ->get();
    }

    /**
     * The teams of the matches, and the chosen one even when it has none.
     *
     * @param  Builder<Fixture>  $matches
     * @return Collection<int, array{slug: string, name: string}>
     */
    private function teams(Builder $matches, string $chosen): Collection
    {
        return Team::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('id', (clone $matches)->select('local_team_id'))
                ->orWhereIn('id', (clone $matches)->select('visitor_team_id'))
                ->when($chosen !== '', fn (Builder $query) => $query->orWhere('slug', $chosen)))
            // SportMonks lists matches whose sides are not decided yet under a team called "TBC".
            ->where('name', '!=', 'TBC')
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->toBase()
            ->map(fn (Team $team): array => ['slug' => $team->slug, 'name' => $team->name]);
    }

    /**
     * The matches starting in the period, plus any still being played: a Test or four-day match that began on
     * an earlier day is part of today's cricket too.
     *
     * @param  Builder<Fixture>  $query
     */
    private function betweenOrInPlay(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where(function (Builder $query) use ($from, $to): void {
            $query->where(fn (Builder $query) => $this->between($query, $from, $to))
                ->orWhere(fn (Builder $query) => $query->inPlay());
        });
    }

    /**
     * @param  Builder<Fixture>  $query
     */
    private function between(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('starting_at', '>=', $from->utc())->where('starting_at', '<', $to->utc());
    }
}
