<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompetitionRefResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Models\Fixture;
use App\Models\League;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MatchdayController extends Controller
{
    private const VIEWS = ['live', 'upcoming', 'result', 'yesterday', 'today', 'tomorrow', 'week'];

    /**
     * Fixtures grouped by competition for the Live Scores and Schedule pages.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'today';
        $competition = $request->query('competition');
        $today = now((string) config('sportmonks.display_timezone'))->startOfDay();

        $query = Fixture::query()->with(['league', 'localTeam', 'visitorTeam', 'venue']);

        match ($view) {
            'live' => $query->inPlay(),
            'upcoming' => $query->upcoming()->where('starting_at', '>=', now()->subHours(3)),
            'result' => $query->finished()->where('starting_at', '>=', now()->subDays(14)),
            'yesterday' => $this->between($query, $today->subDay(), $today),
            'today' => $this->between($query, $today, $today->addDay()),
            'tomorrow' => $this->between($query, $today->addDay(), $today->addDays(2)),
            'week' => $this->between($query, $today, $today->addDays(7)),
        };

        $fixtures = $query
            ->orderBy('starting_at', $view === 'result' ? 'desc' : 'asc')
            ->limit(300)
            ->get();

        $competitions = $fixtures
            ->pluck('league')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $groups = $fixtures
            ->when(is_string($competition) && $competition !== '', fn (Collection $items) => $items->filter(
                fn (Fixture $fixture): bool => $fixture->league?->slug === $competition,
            ))
            ->groupBy('league_id')
            ->map(fn (Collection $matches): array => [
                'competition' => new CompetitionRefResource($matches->first()->league ?? new League(['slug' => 'league', 'name' => 'League'])),
                'matches' => MatchResource::collection($matches->values()),
            ])
            ->sortBy(fn (array $group): string => $group['competition']->name)
            ->values();

        return response()->json([
            'data' => [
                'date' => $today->toDateString(),
                'competitions' => CompetitionRefResource::collection($competitions),
                'groups' => $groups,
            ],
        ]);
    }

    /**
     * @param  Builder<Fixture>  $query
     */
    private function between(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('starting_at', '>=', $from->utc())->where('starting_at', '<', $to->utc());
    }
}
