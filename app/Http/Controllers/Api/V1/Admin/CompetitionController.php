<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\League;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
