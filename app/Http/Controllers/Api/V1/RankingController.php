<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TeamSummaryResource;
use App\Models\TeamRanking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    /**
     * The official team rankings: every format, men's and women's, each table in order of position.
     * The tables are empty until the first sync has stored them; the website then says they are not available.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $rankings = TeamRanking::query()->with('team')->orderBy('position')->get();

        $tables = [];

        foreach ($rankings->groupBy('gender') as $gender => $byGender) {
            foreach (TeamRanking::FORMATS as $format => $label) {
                $rows = $byGender->where('format', $format)->values();

                if ($rows->isEmpty()) {
                    continue;
                }

                $tables[$gender][$format] = $rows->map(fn (TeamRanking $row): array => [
                    'position' => $row->position,
                    'team' => (new TeamSummaryResource($row->team))->resolve($request),
                    'matches' => $row->matches,
                    'points' => $row->points,
                    'rating' => $row->rating,
                ])->all();
            }
        }

        return response()->json(['data' => [
            'formats' => collect(TeamRanking::FORMATS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values(),
            'updatedAt' => $rankings->max('updated_at')?->toIso8601String(),
            'tables' => (object) $tables,
        ]]);
    }
}
