<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncTarget;
use App\Http\Controllers\Controller;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\SitePage;
use App\Models\SyncRun;
use App\Models\Team;
use App\Models\Venue;
use App\Services\SportMonks\SportMonksClient;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Overview numbers, recent sync activity and system status for the admin dashboard.
     */
    public function __invoke(SportMonksClient $client): JsonResponse
    {
        $matches = Fixture::query()->count();
        $players = Player::query()->count();
        $customisedPages = SitePage::query()->count();

        return response()->json(['data' => [
            'stats' => [
                $this->stat('Live matches', Fixture::query()->where('is_live', true)->count(), 'Being played right now', 'globe'),
                $this->stat('Upcoming matches', Fixture::query()->where('starting_at', '>', now())->count(), 'Scheduled to start', 'clock'),
                $this->stat('Matches stored', $matches, 'Synced from SportMonks', 'article'),
                $this->stat('Competitions', League::query()->count(), 'Leagues and tournaments', 'folder'),
                $this->stat('Teams', Team::query()->count(), 'Clubs and national sides', 'users'),
                $this->stat('Players', $players, 'Squad members on file', 'user'),
                $this->stat('Venues', Venue::query()->count(), 'Grounds on file', 'image'),
                $this->stat('Legal pages edited', $customisedPages, 'Of '.count(SitePage::SLUGS).' editable pages', 'draft'),
            ],
            'activity' => $this->activity(),
            'system' => $this->system($client, $matches, $players, $customisedPages),
        ]]);
    }

    /**
     * @return array{label: string, value: string, hint: string, icon: string}
     */
    private function stat(string $label, int $value, string $hint, string $icon): array
    {
        return ['label' => $label, 'value' => number_format($value), 'hint' => $hint, 'icon' => $icon];
    }

    /**
     * The latest sync runs, newest first.
     *
     * @return array<int, array{actor: string, initials: string, action: string, target: string, relative: string, absolute: string}>
     */
    private function activity(): array
    {
        return SyncRun::query()
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(function (SyncRun $run): array {
                $when = $run->finished_at ?? $run->started_at;

                return [
                    'actor' => 'SportMonks sync',
                    'initials' => 'SM',
                    'action' => match ($run->status) {
                        SyncRun::SUCCESS => 'completed',
                        SyncRun::RUNNING => 'started',
                        default => 'failed',
                    },
                    'target' => (SyncTarget::tryFrom($run->target)?->label() ?? $run->target).($run->status === SyncRun::SUCCESS ? " · {$run->records_synced} records" : ''),
                    'relative' => $when->diffForHumans(),
                    'absolute' => $when->format('j M, H:i'),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{name: string, detail: string, status: string, icon: string}>
     */
    private function system(SportMonksClient $client, int $matches, int $players, int $customisedPages): array
    {
        $lastRun = SyncRun::query()->orderByDesc('id')->first();
        $lastSuccess = SyncRun::query()->where('status', SyncRun::SUCCESS)->latest('finished_at')->first();

        [$sportMonksStatus, $sportMonksDetail] = match (true) {
            ! $client->isConfigured() => ['Offline', 'API token is not configured'],
            $lastRun === null => ['Degraded', 'Waiting for the first sync'],
            $lastRun->status === SyncRun::FAILED => ['Degraded', 'Last sync failed: '.($lastRun->message ?: 'unknown error')],
            default => ['Connected', 'Last sync '.($lastSuccess?->finished_at?->diffForHumans() ?? 'not finished yet')],
        };

        return [
            ['name' => 'SportMonks API', 'detail' => $sportMonksDetail, 'status' => $sportMonksStatus, 'icon' => 'plug'],
            [
                'name' => 'Match data',
                'detail' => $matches > 0 ? number_format($matches).' matches and '.number_format($players).' players stored' : 'No matches stored yet',
                'status' => $matches > 0 ? 'Connected' : 'Degraded',
                'icon' => 'storage',
            ],
            [
                'name' => 'Legal pages',
                'detail' => $customisedPages.' of '.count(SitePage::SLUGS).' pages customised, the rest use the built-in text',
                'status' => 'Connected',
                'icon' => 'globe',
            ],
        ];
    }
}
