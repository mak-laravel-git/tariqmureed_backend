<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlayerCardResource;
use App\Http\Resources\Api\V1\PlayerPageResource;
use App\Models\Player;
use App\Services\SportMonks\SportMonksClient;
use App\Services\SportMonks\SportMonksException;
use App\Services\SportMonks\SportMonksSyncer;
use App\Support\TrendingPlayers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

class PlayerController extends Controller
{
    /**
     * Every player, a page at a time, with an optional name search and role filter.
     * Players with a photo come first; the rest follow, each group by name.
     * GET /players?search=&role=batter|bowler|allrounder|wicketkeeper&page=&per_page=
     */
    public function index(Request $request): JsonResponse
    {
        // "%" and "_" are wildcards in a LIKE pattern, not part of a name.
        $search = $request->string('search')->replace(['%', '_'], '')->trim()->limit(100, '')->value();
        $role = $request->string('role')->value();

        $players = Player::query()
            ->select(['id', 'team_id', 'slug', 'fullname', 'image', 'position'])
            ->with('team:id,name,national_team')
            ->when($search !== '', fn (Builder $query) => $query->where('fullname', 'like', "%{$search}%"))
            ->when($role === 'batter', fn (Builder $query) => $query->whereIn('position', ['Batsman', 'Top Order Batter', 'Middle Order Batter']))
            ->when($role === 'bowler', fn (Builder $query) => $query->where('position', 'Bowler'))
            ->when($role === 'allrounder', fn (Builder $query) => $query->where('position', 'like', '%Allrounder%'))
            ->when($role === 'wicketkeeper', fn (Builder $query) => $query->where('position', 'Wicketkeeper'))
            // SportMonks gives players without a photo a "placeholder" picture.
            ->orderByRaw("case when image is null or image = '' or image like '%placeholder%' then 1 else 0 end")
            ->orderBy('fullname')
            ->orderBy('id')
            ->paginate(min(max($request->integer('per_page', 24), 1), 48));

        return response()->json(['data' => [
            'players' => PlayerCardResource::collection($players->items())->resolve($request),
            'total' => $players->total(),
            'page' => $players->currentPage(),
            'lastPage' => $players->lastPage(),
        ]]);
    }

    /**
     * The players in the news (home page "Trending Players"). Working the list out reads
     * every career record, so it is kept for half an hour and then renewed after the response has been sent.
     */
    public function trending(): JsonResponse
    {
        $players = Cache::flexible('players.trending', [1800, 86400], fn (): array => TrendingPlayers::list());

        return response()->json(['data' => $players]);
    }

    /**
     * Player profile. Career statistics are fetched from SportMonks the first time a profile is opened,
     * and renewed after the response when they are over a week old, so a visitor does not wait for that.
     * A failed call falls back to stored data.
     */
    public function show(string $slug, SportMonksClient $client, SportMonksSyncer $syncer): PlayerPageResource
    {
        $player = Player::query()->with('team')->where('slug', $slug)->firstOrFail();

        if ($client->isConfigured()) {
            if ($player->details_synced_at === null) {
                $this->refresh($player, $syncer);
                $player->refresh()->load('team');
            } elseif ($player->details_synced_at->lt(now()->subWeek())) {
                // A copy is refreshed, so the response is not changed while it is being written.
                $stale = clone $player;
                defer(fn () => $this->refresh($stale, $syncer));
            }
        }

        return new PlayerPageResource($player);
    }

    private function refresh(Player $player, SportMonksSyncer $syncer): void
    {
        try {
            $syncer->refreshPlayer($player);
        } catch (SportMonksException $exception) {
            report($exception);
        }
    }
}
