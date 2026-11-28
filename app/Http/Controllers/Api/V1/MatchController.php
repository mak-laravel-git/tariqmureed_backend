<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchDetailResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Models\Fixture;
use App\Models\League;
use App\Services\SportMonks\SportMonksClient;
use App\Services\SportMonks\SportMonksException;
use App\Services\SportMonks\SportMonksSyncer;
use App\Support\MatchPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class MatchController extends Controller
{
    private const RELATIONS = ['league', 'localTeam', 'visitorTeam', 'venue'];

    private const LIVE_REFRESH_SECONDS = 30;

    /**
     * Matches filtered by state ("live" includes innings and meal breaks). With order=priority the live and
     * result lists put the important competitions first (see MatchPriority), the live list keeping the matches
     * paused for a break after those being played; every other list goes by time.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 200);
        $state = (string) $request->query('state', 'all');

        if ($request->query('order') === 'priority' && in_array($state, ['live', 'result'], true)) {
            return MatchResource::collection($this->byPriority($state, $limit));
        }

        $matches = match ($state) {
            'live' => $this->live()->get(),
            'break' => $this->query()->whereIn('status', MatchState::BREAK_STATUSES)->orderBy('starting_at')->limit($limit)->get(),
            'upcoming' => $this->upcoming()->limit($limit)->get(),
            'result' => $this->results()->limit($limit)->get(),
            default => $this->live()->get()
                ->concat($this->upcoming()->limit($limit)->get())
                ->concat($this->results()->limit($limit)->get())
                ->take($limit)
                ->values(),
        };

        return MatchResource::collection($matches);
    }

    public function summary(): JsonResponse
    {
        $timezone = (string) config('sportmonks.display_timezone');
        $today = now($timezone);

        return response()->json([
            'matchesToday' => Fixture::query()
                ->whereBetween('starting_at', [$today->startOfDay(), $today->endOfDay()])
                ->count(),
            'liveNow' => Fixture::query()->inPlay()->count(),
            'competitionsTracked' => League::query()->where('is_featured', true)->count(),
            'dataProvider' => (string) config('sportmonks.provider_label'),
        ]);
    }

    /**
     * One match with everything the match page shows. The details are fetched from SportMonks when the page is
     * opened (see needsDetails); a failed call falls back to what is stored.
     */
    public function show(string $slug, SportMonksClient $client, SportMonksSyncer $syncer): MatchDetailResource
    {
        $fixture = Fixture::query()->with(self::RELATIONS)->where('slug', $slug)->firstOrFail();

        // The lock keeps many visitors of the same match to one call.
        if ($client->isConfigured() && $this->needsDetails($fixture) && Cache::add("sportmonks:match-centre:{$fixture->id}", true, self::LIVE_REFRESH_SECONDS)) {
            try {
                $syncer->refreshMatchCentre($fixture);
                $fixture->refresh()->load(self::RELATIONS);
            } catch (SportMonksException $exception) {
                report($exception);
            }
        }

        return new MatchDetailResource($fixture);
    }

    /**
     * A match in play (or about to start, for the toss and line-ups) is refreshed every half minute while someone
     * is watching; a finished one is fetched once.
     */
    private function needsDetails(Fixture $fixture): bool
    {
        $fetched = $fixture->centre_synced_at;

        return match (true) {
            in_array($fixture->state(), [MatchState::LIVE, MatchState::BREAK], true),
            $fixture->state() === MatchState::UPCOMING && $fixture->starting_at->between(now()->subHours(6), now()->addHour()) => $fetched === null || $fetched->lt(now()->subSeconds(self::LIVE_REFRESH_SECONDS)),
            $fixture->state() === MatchState::RESULT => $fetched === null,
            default => false,
        };
    }

    /**
     * @param  'live'|'result'  $state
     * @return Collection<int, Fixture>
     */
    private function byPriority(string $state, int $limit): Collection
    {
        if ($state === 'result') {
            return MatchPriority::takeResults($this->results()->reorder(), $limit);
        }

        $breaks = MatchState::BREAK_STATUSES;
        // Matches being played go before those paused for a break, however important the paused one is.
        $live = $this->query()->inPlay()
            ->orderByRaw('case when status in ('.implode(', ', array_fill(0, count($breaks), '?')).') then 1 else 0 end', $breaks);
        MatchPriority::rank($live);

        return $live->orderBy('starting_at')->get();
    }

    /**
     * @return Builder<Fixture>
     */
    private function query(): Builder
    {
        return Fixture::query()->with(self::RELATIONS);
    }

    /**
     * @return Builder<Fixture>
     */
    private function live(): Builder
    {
        return $this->query()->inPlay()->orderBy('starting_at');
    }

    /**
     * @return Builder<Fixture>
     */
    private function upcoming(): Builder
    {
        return $this->query()->upcoming()->where('starting_at', '>=', now()->subHours(3))->orderBy('starting_at');
    }

    /**
     * @return Builder<Fixture>
     */
    private function results(): Builder
    {
        return $this->query()->finished()->orderByDesc('starting_at');
    }
}
