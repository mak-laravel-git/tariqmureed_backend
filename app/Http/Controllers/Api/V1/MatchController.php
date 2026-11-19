<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MatchState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchDetailResource;
use App\Http\Resources\Api\V1\MatchResource;
use App\Models\Fixture;
use App\Models\League;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MatchController extends Controller
{
    private const RELATIONS = ['league', 'localTeam', 'visitorTeam', 'venue'];

    /**
     * Matches filtered by state ("live" includes innings and meal breaks).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 200);
        $state = (string) $request->query('state', 'all');

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

    public function show(string $slug): MatchDetailResource
    {
        return new MatchDetailResource(
            Fixture::query()->with(self::RELATIONS)->where('slug', $slug)->firstOrFail(),
        );
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
