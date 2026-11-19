<?php

namespace App\Console\Commands;

use App\Enums\SyncTarget;
use App\Models\SyncRun;
use App\Services\SportMonks\SportMonksClient;
use App\Services\SportMonks\SportMonksSyncer;
use Illuminate\Console\Command;

class SportMonksSyncCommand extends Command
{
    protected $signature = 'sportmonks:sync {target=all : leagues, venues, teams, fixtures, squads, standings, details, live or all}';

    protected $description = 'Copy SportMonks cricket data into the local database';

    public function handle(SportMonksClient $client, SportMonksSyncer $syncer): int
    {
        $target = SyncTarget::tryFrom((string) $this->argument('target'));

        if (! $target) {
            $this->components->error('Unknown target. Use: '.collect(SyncTarget::cases())->pluck('value')->implode(', ').'.');

            return self::INVALID;
        }

        if (! $client->isConfigured()) {
            $this->components->error('SPORTMONKS_API_TOKEN is not set in .env.');

            return self::FAILURE;
        }

        $runs = $target === SyncTarget::ALL ? $syncer->runAll() : [$syncer->run($target)];

        foreach ($runs as $run) {
            $label = SyncTarget::from($run->target)->label();
            $detail = $run->status === SyncRun::SUCCESS
                ? "{$run->records_synced} records"
                : "failed: {$run->message}";

            $this->components->twoColumnDetail($label, $detail);
        }

        return collect($runs)->contains(fn (SyncRun $run): bool => $run->status === SyncRun::FAILED)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
