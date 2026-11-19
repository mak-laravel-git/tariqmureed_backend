<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompetitionRefResource;
use App\Models\League;
use App\Support\LeagueSchedules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompetitionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CompetitionRefResource::collection(
            League::query()
                ->when($request->boolean('featured'), fn ($query) => $query->where('is_featured', true))
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * Up to four series for the home page: those in progress first, then the next to start.
     */
    public function spotlight(): JsonResponse
    {
        $schedules = LeagueSchedules::all();
        $order = ['In progress' => 0, 'Upcoming' => 1, 'Completed' => 2];

        $items = League::query()
            ->whereIn('id', $schedules->keys())
            ->get()
            ->sortBy(fn (League $league): string => $order[$schedules[$league->id]['status']].$schedules[$league->id]['first']->format('YmdHis'))
            ->take(4)
            ->map(function (League $league) use ($schedules): array {
                $schedule = $schedules[$league->id];

                return [
                    'id' => $league->id,
                    'slug' => $league->slug,
                    'name' => $league->name,
                    'image' => $league->image ?? '',
                    'format' => $league->format ?? '',
                    'location' => $league->category ?? '',
                    'status' => $schedule['status'],
                    'description' => "{$schedule['count']} matches on TMN Sport",
                    'dateRange' => LeagueSchedules::dateRange($schedule['first'], $schedule['last']),
                    'matchCount' => $schedule['count'],
                ];
            })
            ->values();

        return response()->json(['data' => $items]);
    }
}
