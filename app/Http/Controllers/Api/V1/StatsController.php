<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\CricketStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class StatsController extends Controller
{
    private const RECORD_GROUPS = ['batting', 'bowling', 'team', 'tournament'];

    /**
     * Leaderboards for a scope and format, from the data feed. Kept for ten minutes: they are read from every
     * player's career figures.
     */
    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['player', 'team', 'tournament'])],
            'format' => ['nullable', Rule::in(array_keys(CricketStats::FORMATS))],
        ]);
        $scope = $data['scope'] ?? 'player';
        $format = $data['format'] ?? null;

        $tables = Cache::remember("stats:{$scope}:".($format ?? 'all'), now()->addMinutes(10), fn (): array => (new CricketStats)->tables($scope, $format));

        return response()->json(['data' => [
            'scope' => $scope,
            'format' => $format,
            'metricCount' => count($tables),
            'tables' => $tables,
        ]]);
    }

    /**
     * The best mark of each kind per format, filtered by group and format.
     */
    public function records(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['nullable', Rule::in(self::RECORD_GROUPS)],
            'format' => ['nullable', Rule::in(array_keys(CricketStats::FORMATS))],
        ]);
        $group = $data['group'] ?? null;
        $format = $data['format'] ?? null;

        $all = Cache::remember('records:'.($format ?? 'all'), now()->addMinutes(10), fn (): array => (new CricketStats)->records($format));
        $counts = collect(self::RECORD_GROUPS)->mapWithKeys(fn (string $name): array => [$name => collect($all)->where('group', $name)->count()]);
        $records = $group ? array_values(array_filter($all, fn (array $record): bool => $record['group'] === $group)) : $all;

        return response()->json(['data' => [
            'group' => $group,
            'format' => $format,
            'total' => count($records),
            'groupCounts' => $counts,
            'records' => $records,
        ]]);
    }
}
