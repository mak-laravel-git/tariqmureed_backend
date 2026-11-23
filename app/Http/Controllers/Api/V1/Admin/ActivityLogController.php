<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * What was done in the dashboard, newest first, as read-only rows of the shared admin table.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 100);
        $search = trim((string) $request->query('q'));

        $logs = ActivityLog::query()
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void {
                $inner->where('actor', 'like', "%{$search}%")->orWhere('target', 'like', "%{$search}%");
            }))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $logs->getCollection()->map(fn (ActivityLog $log): array => $this->record($log))->values(),
            'meta' => ['total' => $logs->total()],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->record(ActivityLog::query()->findOrFail($id))]);
    }

    /**
     * @return array{id: string, title: string, subtitle: string, owner: string, status: string, updatedAt: string, notes: null}
     */
    private function record(ActivityLog $log): array
    {
        return [
            'id' => (string) $log->id,
            'title' => $log->target,
            'subtitle' => "{$log->action} {$log->subject}",
            'owner' => $log->actor,
            'status' => 'Active',
            'updatedAt' => $log->created_at->diffForHumans(),
            'notes' => null,
        ];
    }
}
