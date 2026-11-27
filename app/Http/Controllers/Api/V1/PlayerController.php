<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PlayerPageResource;
use App\Models\Player;
use App\Services\SportMonks\SportMonksClient;
use App\Services\SportMonks\SportMonksException;
use App\Services\SportMonks\SportMonksSyncer;
use App\Support\TrendingPlayers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PlayerController extends Controller
{
    /**
     * The players in the news (home page "Trending Players" and the Players page). Working the list out reads
     * every career record, so it is kept for half an hour and then renewed after the response has been sent.
     */
    public function trending(): JsonResponse
    {
        $players = Cache::flexible('players.trending', [1800, 86400], fn (): array => TrendingPlayers::list());

        return response()->json(['data' => $players]);
    }

    /**
     * Player profile. Career statistics are fetched from SportMonks the first time a
     * profile is opened (and again after a week); a failed call falls back to stored data.
     */
    public function show(string $slug, SportMonksClient $client, SportMonksSyncer $syncer): PlayerPageResource
    {
        $player = Player::query()->with('team')->where('slug', $slug)->firstOrFail();

        $stale = $player->details_synced_at === null || $player->details_synced_at->lt(now()->subWeek());

        if ($stale && $client->isConfigured()) {
            try {
                $syncer->refreshPlayer($player);
                $player->refresh()->load('team');
            } catch (SportMonksException $exception) {
                report($exception);
            }
        }

        return new PlayerPageResource($player);
    }
}
