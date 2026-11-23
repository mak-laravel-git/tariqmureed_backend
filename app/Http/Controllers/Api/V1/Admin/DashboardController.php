<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SyncTarget;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Advertisement;
use App\Models\Article;
use App\Models\Category;
use App\Models\Fixture;
use App\Models\MediaAsset;
use App\Models\Player;
use App\Models\SitePage;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Writer;
use App\Services\SportMonks\SportMonksClient;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Overview numbers, recent activity and system status for the admin dashboard.
     */
    public function __invoke(SportMonksClient $client): JsonResponse
    {
        $matches = Fixture::query()->count();
        $players = Player::query()->count();
        $customisedPages = SitePage::query()->count();

        return response()->json(['data' => [
            'stats' => [
                $this->stat('Total articles', Article::query()->count(), 'All content in the CMS', 'article'),
                $this->stat('Published', Article::query()->visible()->count(), 'Live on the public site', 'globe'),
                $this->stat('Drafts', Article::query()->where('status', Article::DRAFT)->count(), 'In progress', 'draft'),
                $this->stat('Pending approval', Article::query()->where('status', Article::PENDING)->count(), 'Awaiting review', 'clock'),
                $this->stat('Categories', Category::query()->count(), 'Editorial taxonomy', 'folder'),
                $this->stat('Authors', Writer::query()->count(), 'Published contributors', 'users'),
                $this->stat('Media assets', MediaAsset::query()->count(), 'Images and thumbnails', 'image'),
                $this->stat('Active staff', User::query()->count(), 'Administrator accounts', 'user'),
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
     * The latest dashboard actions and sync runs together, newest first.
     *
     * @return array<int, array{actor: string, initials: string, action: string, target: string, relative: string, absolute: string}>
     */
    private function activity(): array
    {
        $actions = ActivityLog::query()->latest('id')->limit(6)->get()->map(fn (ActivityLog $log): array => [
            'at' => $log->created_at,
            'actor' => $log->actor,
            'initials' => $this->initials($log->actor),
            'action' => "{$log->action} {$log->subject}",
            'target' => $log->target,
        ]);

        $syncs = SyncRun::query()->latest('id')->limit(6)->get()->map(function (SyncRun $run): array {
            return [
                'at' => $run->finished_at ?? $run->started_at,
                'actor' => 'SportMonks sync',
                'initials' => 'SM',
                'action' => match ($run->status) {
                    SyncRun::SUCCESS => 'completed',
                    SyncRun::RUNNING => 'started',
                    default => 'failed',
                },
                'target' => (SyncTarget::tryFrom($run->target)?->label() ?? $run->target).($run->status === SyncRun::SUCCESS ? " · {$run->records_synced} records" : ''),
            ];
        });

        return $actions->concat($syncs)
            ->sortByDesc(fn (array $entry): int => $entry['at']->getTimestamp())
            ->take(6)
            ->map(fn (array $entry): array => [
                'actor' => $entry['actor'],
                'initials' => $entry['initials'],
                'action' => $entry['action'],
                'target' => $entry['target'],
                'relative' => $entry['at']->diffForHumans(),
                'absolute' => $entry['at']->format('j M, H:i'),
            ])
            ->values()
            ->all();
    }

    private function initials(string $name): string
    {
        return mb_strtoupper(collect(preg_split('/\s+/', trim($name)) ?: [])->map(fn (string $part): string => mb_substr($part, 0, 1))->take(2)->implode(''));
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
            [
                'name' => 'SportMonks API',
                'detail' => $sportMonksDetail.($matches > 0 ? ' · '.number_format($matches).' matches, '.number_format($players).' players' : ''),
                'status' => $sportMonksStatus,
                'icon' => 'plug',
            ],
            ['name' => 'Media storage', 'detail' => number_format(MediaAsset::query()->count()).' assets stored in the library', 'status' => 'Connected', 'icon' => 'storage'],
            [
                'name' => 'Public website',
                'detail' => number_format(Article::query()->visible()->count()).' published stories · '.$customisedPages.' of '.count(SitePage::SLUGS).' legal pages customised',
                'status' => 'Connected',
                'icon' => 'globe',
            ],
            ['name' => 'Advertising', 'detail' => number_format(Advertisement::query()->running()->count()).' campaigns running on the website', 'status' => 'Connected', 'icon' => 'card'],
        ];
    }
}
