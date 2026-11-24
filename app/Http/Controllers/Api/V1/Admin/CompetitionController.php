<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\League;
use App\Support\LeagueSchedules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompetitionController extends Controller
{
    /**
     * Competitions synced from SportMonks, as read-only rows of the shared admin table.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 100);
        $search = trim((string) $request->query('q'));

        // Competitions are always live data, so the "pending" and "archived" tabs have nothing to show.
        $query = in_array($request->query('tab', 'all'), ['all', 'active'], true)
            ? League::query()
            : League::query()->whereRaw('1 = 0');

        $leagues = $query
            ->when($search !== '', fn ($builder) => $builder->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $leagues->getCollection()->map(fn (League $league): array => $this->record($league))->values(),
            'meta' => ['total' => $leagues->total()],
        ]);
    }

    /**
     * The series strip as the website shows it: the competitions on show in their order, and the hidden ones.
     * Each row says whether the competition has matches, so the editor can tell a live series from a dead one.
     */
    public function arrangement(): JsonResponse
    {
        $schedules = LeagueSchedules::all();
        $row = fn (League $league): array => [
            'id' => (string) $league->id,
            'name' => $league->name,
            'detail' => collect([$league->code, $league->format, $league->category])->filter()->implode(' · '),
            'status' => $schedules->has($league->id) ? $schedules[$league->id]['status'] : 'No matches yet',
        ];

        $leagues = League::query()->orderByRaw('sort_order is null')->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => [
            'visible' => $leagues->where('is_featured', true)->map($row)->values(),
            'hidden' => $leagues->where('is_featured', false)->map($row)->values(),
        ]]);
    }

    /**
     * Save the arrangement: the competitions listed are shown in that order, every other one is hidden.
     * A competition that arrives later from SportMonks is shown after these, A to Z, until it is arranged.
     */
    public function arrange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visible' => ['present', 'array'],
            'visible.*' => ['integer', 'distinct', 'exists:leagues,id'],
        ]);

        DB::transaction(function () use ($data): void {
            League::query()->update(['is_featured' => false, 'sort_order' => null]);

            foreach (array_values($data['visible']) as $position => $id) {
                League::query()->whereKey($id)->update(['is_featured' => true, 'sort_order' => $position + 1]);
            }
        });

        ActivityLog::record($request->user(), 'updated', 'series', 'Series order and visibility');

        return $this->arrangement();
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->record(League::query()->findOrFail($id))]);
    }

    /**
     * @return array{id: string, title: string, subtitle: string, owner: string, status: string, updatedAt: string, notes: null}
     */
    private function record(League $league): array
    {
        return [
            'id' => (string) $league->id,
            'title' => $league->name,
            'subtitle' => collect([$league->code, $league->format])->filter()->implode(' · ') ?: 'Competition',
            'owner' => 'SportMonks',
            'status' => $league->is_featured ? 'Active' : 'Archived',
            'updatedAt' => $league->updated_at->diffForHumans(),
            'notes' => null,
        ];
    }
}
