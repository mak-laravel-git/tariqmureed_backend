<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MatchResource;
use App\Http\Resources\Api\V1\TeamSummaryResource;
use App\Models\Fixture;
use App\Models\Team;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamController extends Controller
{
    /**
     * Teams that appear in the synced fixtures.
     */
    public function index(): AnonymousResourceCollection
    {
        return TeamSummaryResource::collection($this->playingTeams()->orderBy('name')->get());
    }

    /**
     * National and domestic sides grouped for the Teams page.
     */
    public function directory(): JsonResponse
    {
        $inPlay = Fixture::query()->inPlay()->get(['local_team_id', 'visitor_team_id'])
            ->flatMap(fn (Fixture $fixture): array => [$fixture->local_team_id, $fixture->visitor_team_id])
            ->unique();

        $items = $this->playingTeams()->orderBy('name')->get()->map(fn (Team $team): array => [
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
            'ranks' => [],
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
     * @return Builder<Team>
     */
    private function playingTeams(): Builder
    {
        return Team::query()->where(fn (Builder $query) => $query
            ->whereIn('id', Fixture::query()->select('local_team_id'))
            ->orWhereIn('id', Fixture::query()->select('visitor_team_id')));
    }
}
