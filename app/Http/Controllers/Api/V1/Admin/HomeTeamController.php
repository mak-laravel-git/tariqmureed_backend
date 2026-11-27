<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Fixture;
use App\Models\Team;
use App\Support\HomeTeams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeTeamController extends Controller
{
    /**
     * The teams chosen for the home page in their order, and every other team (those with matches first).
     * While none is chosen, `automatic` names the teams the website picks by itself.
     */
    public function index(): JsonResponse
    {
        $selected = HomeTeams::selected();
        $matches = Fixture::query()->get(['local_team_id', 'visitor_team_id'])
            ->flatMap(fn (Fixture $fixture): array => [$fixture->local_team_id, $fixture->visitor_team_id])
            ->filter()
            ->countBy();
        $row = fn (Team $team): array => [
            'id' => (string) $team->id,
            'name' => $team->name,
            'detail' => $team->national_team ? 'International' : 'Domestic',
            'matches' => (int) $matches->get($team->id, 0),
        ];

        $available = Team::query()
            ->whereNotIn('id', $selected->modelKeys())
            ->orderBy('name')
            ->get(['id', 'name', 'national_team'])
            ->sortByDesc(fn (Team $team): bool => $matches->has($team->id));

        return response()->json(['data' => [
            'limit' => HomeTeams::LIMIT,
            'selected' => $selected->map($row)->values(),
            'available' => $available->map($row)->values(),
            'automatic' => $selected->isEmpty() ? HomeTeams::list()->pluck('name') : [],
        ]]);
    }

    /**
     * Save the choice: the teams listed are shown in that order.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'teams' => ['present', 'array', 'max:'.HomeTeams::LIMIT],
            'teams.*' => ['integer', 'distinct', 'exists:teams,id'],
        ]);

        HomeTeams::save($data['teams']);

        ActivityLog::record($request->user(), 'updated', 'teams', 'Home page teams');

        return $this->index();
    }
}
