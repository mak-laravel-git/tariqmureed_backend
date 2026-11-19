<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncTarget;
use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\SportMonks\SportMonksClient;
use App\Services\SportMonks\SportMonksSyncer;
use Illuminate\Http\JsonResponse;

class SportMonksIntegrationController extends Controller
{
    /**
     * Connection state and the latest run of every sync target (shown on the admin Integration page).
     */
    public function show(SportMonksClient $client): JsonResponse
    {
        $latest = SyncRun::query()
            ->orderByDesc('id')
            ->get()
            ->unique('target')
            ->keyBy('target');

        $lastSuccess = SyncRun::query()->where('status', SyncRun::SUCCESS)->latest('finished_at')->first();
        $failedTargets = $latest->filter(fn (SyncRun $run): bool => $run->status === SyncRun::FAILED)->count();
        $usage = $client->usage();

        $connection = match (true) {
            ! $client->isConfigured() => 'Not configured',
            $usage['blocked_until'] !== null => 'Rate limited',
            $latest->isEmpty() => 'Waiting for first sync',
            $failedTargets > 0 => 'Error',
            default => 'Connected',
        };

        $jobs = collect(SyncTarget::cases())
            ->reject(fn (SyncTarget $target): bool => $target === SyncTarget::ALL)
            ->filter(fn (SyncTarget $target): bool => $latest->has($target->value))
            ->map(function (SyncTarget $target) use ($latest): array {
                /** @var SyncRun $run */
                $run = $latest[$target->value];
                $when = ($run->finished_at ?? $run->started_at)->diffForHumans();

                return [
                    'id' => $target->value,
                    'name' => $target->label(),
                    'detail' => match ($run->status) {
                        SyncRun::SUCCESS => "{$when} · {$run->records_synced} records",
                        SyncRun::RUNNING => "Started {$when}",
                        default => "{$when} · ".($run->message ?: 'Failed'),
                    },
                    'status' => match ($run->status) {
                        SyncRun::SUCCESS => 'Success',
                        SyncRun::RUNNING => 'Running',
                        default => 'Failed',
                    },
                ];
            })
            ->values();

        return response()->json(['data' => [
            'provider' => 'SportMonks',
            'connection' => $connection,
            'lastSync' => $lastSuccess?->finished_at?->diffForHumans() ?? '—',
            'recordsUpdated' => number_format((int) SyncRun::query()
                ->where('status', SyncRun::SUCCESS)
                ->where('started_at', '>=', now()->subDay())
                ->sum('records_synced')),
            'openErrors' => (string) $failedTargets,
            'jobs' => $jobs,
        ]]);
    }

    /**
     * Start a full sync after the response is sent, so no queue worker is needed.
     */
    public function sync(SportMonksClient $client): JsonResponse
    {
        if (! $client->isConfigured()) {
            return response()->json(['message' => 'The SportMonks API token is not configured. Set SPORTMONKS_API_TOKEN in the backend .env file.'], 422);
        }

        dispatch(fn () => app(SportMonksSyncer::class)->runAll())->afterResponse();

        return response()->json(['message' => 'Sync started.'], 202);
    }
}
