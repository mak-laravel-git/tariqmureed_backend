<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Fixture;
use App\Models\League;
use App\Support\MatchPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveCentreController extends Controller
{
    /**
     * The competitions arranged for the home page's Live Match Center in their order, and every other one: those
     * with matches around now first, internationals before the rest.
     */
    public function index(): JsonResponse
    {
        $window = [now()->subDays(MatchPriority::WINDOW_DAYS), now()->addDays(MatchPriority::WINDOW_DAYS)];
        $matches = Fixture::query()
            ->where(fn (Builder $query) => $query
                ->whereBetween('starting_at', $window)
                ->orWhere(fn (Builder $query) => $query->inPlay()))
            ->get(['league_id'])
            ->countBy('league_id');

        $leagues = League::query()->orderBy('name')->get(['id', 'name', 'format', 'category'])->keyBy('id');
        $chosen = collect(MatchPriority::chosen())->map(fn (int $id): ?League => $leagues->get($id))->filter();

        $row = fn (League $league): array => [
            'id' => (string) $league->id,
            'name' => $league->name,
            'detail' => collect([$league->category, $league->format])->filter()->implode(' · '),
            'matches' => (int) $matches->get($league->id, 0),
        ];

        $available = $leagues
            ->except($chosen->pluck('id')->all())
            ->sortBy(fn (League $league): array => [! $matches->has($league->id), $league->category !== 'International', $league->name]);

        return response()->json(['data' => [
            'windowDays' => MatchPriority::WINDOW_DAYS,
            'selected' => $chosen->map($row)->values(),
            'available' => $available->map($row)->values(),
        ]]);
    }

    /**
     * Save the arrangement: the competitions listed go first, in that order.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leagues' => ['present', 'array'],
            'leagues.*' => ['integer', 'distinct', 'exists:leagues,id'],
        ]);

        MatchPriority::save($data['leagues']);

        ActivityLog::record($request->user(), 'updated', 'series', 'Live Match Center order');

        return $this->index();
    }
}
