<?php

namespace App\Services\SportMonks;

use App\Enums\MatchState;
use App\Enums\SyncTarget;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\Season;
use App\Models\Standing;
use App\Models\SyncRun;
use App\Models\Team;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies SportMonks cricket data into this application's database.
 *
 * Visitors never trigger SportMonks calls: the API reads these tables only, so the
 * hourly call limit depends on the schedule, not on traffic. Every row keeps the
 * SportMonks id as its primary key, which makes every sync idempotent.
 */
class SportMonksSyncer
{
    /**
     * Problems with single items that did not stop the run.
     *
     * @var array<int, string>
     */
    private array $notes = [];

    /**
     * Whether the last failed run hit an error that makes further calls pointless.
     */
    private bool $lastRunWasFatal = false;

    public function __construct(private readonly SportMonksClient $client) {}

    /**
     * Run one target and record the outcome in sync_runs. Never throws.
     */
    public function run(SyncTarget $target): SyncRun
    {
        $this->notes = [];
        $this->lastRunWasFatal = false;

        $run = SyncRun::query()->create([
            'target' => $target->value,
            'status' => SyncRun::RUNNING,
            'started_at' => now(),
        ]);

        try {
            $records = match ($target) {
                SyncTarget::LEAGUES => $this->syncLeagues(),
                SyncTarget::VENUES => $this->syncVenues(),
                SyncTarget::TEAMS => $this->syncTeams(),
                SyncTarget::FIXTURES => $this->syncFixtures(),
                SyncTarget::SQUADS => $this->syncSquads(),
                SyncTarget::STANDINGS => $this->syncStandings(),
                SyncTarget::DETAILS => $this->syncDetails(),
                SyncTarget::LIVE => $this->syncLive(),
                SyncTarget::ALL => 0,
            };

            $run->update([
                'status' => SyncRun::SUCCESS,
                'records_synced' => $records,
                'message' => $this->summariseNotes(),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            if ($exception instanceof SportMonksException) {
                $this->lastRunWasFatal = $exception->isFatal();
            } else {
                report($exception);
            }

            $run->update([
                'status' => SyncRun::FAILED,
                'message' => Str::limit($exception->getMessage(), 500),
                'finished_at' => now(),
            ]);
        }

        return $run;
    }

    /**
     * Run every target in dependency order. Stops early on a fatal error such as a rejected token.
     *
     * @return array<int, SyncRun>
     */
    public function runAll(): array
    {
        $runs = [];

        foreach (SyncTarget::fullSync() as $target) {
            $runs[] = $run = $this->run($target);

            if ($run->status === SyncRun::FAILED && $this->lastRunWasFatal) {
                break;
            }
        }

        return $runs;
    }

    public function syncLeagues(): int
    {
        $count = 0;

        foreach ($this->rows($this->client->get('leagues', ['include' => 'seasons'])) as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id === 0 || ! $this->allowsLeague($id)) {
                continue;
            }

            $attributes = [
                'name' => (string) ($row['name'] ?? "League {$id}"),
                'code' => $row['code'] ?? null,
                'image' => $row['image_path'] ?? null,
                'type' => $row['type'] ?? null,
                'country_id' => $row['country_id'] ?? null,
                'current_season_id' => $row['season_id'] ?? null,
            ];

            $league = League::query()->find($id);

            if ($league) {
                $league->update($attributes);
            } else {
                League::query()->create($attributes + [
                    'id' => $id,
                    'slug' => $this->uniqueSlug(League::class, $attributes['name'], $id),
                ]);
            }

            foreach ($this->many($row, 'seasons') as $season) {
                Season::query()->updateOrCreate(['id' => $season['id']], [
                    'league_id' => $id,
                    'name' => (string) ($season['name'] ?? ''),
                    'is_current' => (int) $season['id'] === (int) ($row['season_id'] ?? 0),
                ]);
            }

            $count++;
        }

        return $count;
    }

    public function syncVenues(): int
    {
        $count = 0;

        foreach ($this->rows($this->client->get('venues')) as $row) {
            if ($this->upsertVenue($row)) {
                $count++;
            }
        }

        return $count;
    }

    public function syncTeams(): int
    {
        $count = 0;

        foreach ($this->rows($this->client->get('teams')) as $row) {
            if ($this->upsertTeam($row)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Fixtures from a few days back to a few weeks ahead, with scores for finished matches.
     */
    public function syncFixtures(): int
    {
        $from = now()->subDays((int) config('sportmonks.sync.days_back'))->toDateString();
        $to = now()->addDays((int) config('sportmonks.sync.days_ahead'))->toDateString();
        $count = 0;

        $rows = $this->client->paginate('fixtures', [
            'filter[starts_between]' => "{$from},{$to}",
            'include' => 'localteam,visitorteam,venue,runs',
        ]);

        foreach ($rows as $row) {
            if ($this->upsertFixture($row)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * One livescores call updates every match in play with its scoreboard and lineups.
     * Skipped while no match is scheduled around now, so quiet hours cost no API calls.
     */
    public function syncLive(): int
    {
        if (! $this->shouldPollLive()) {
            return 0;
        }

        $count = 0;
        $body = $this->client->get('livescores', [
            'include' => 'localteam,visitorteam,venue,runs,batting,bowling,lineup',
        ]);

        foreach ($this->rows($body) as $row) {
            if ($this->upsertFixture($row)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Full scorecards for matches that finished, and a status refresh for matches that
     * dropped out of the live feed without a final status.
     */
    public function syncDetails(): int
    {
        $fixtures = Fixture::query()
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $inner) => $inner->finished()->whereNull('details_synced_at'))
                    ->orWhere(fn (Builder $inner) => $inner->inPlay()->where('updated_at', '<', now()->subMinutes(10)));
            })
            ->orderByDesc('starting_at')
            ->limit((int) config('sportmonks.sync.details_per_run'))
            ->get();

        $count = 0;

        foreach ($fixtures as $fixture) {
            try {
                $body = $this->client->get("fixtures/{$fixture->id}", [
                    'include' => 'localteam,visitorteam,venue,runs,batting,bowling,lineup',
                ]);
            } catch (SportMonksException $exception) {
                if ($exception->isFatal()) {
                    throw $exception;
                }

                $this->notes[] = "fixture {$fixture->id}: {$exception->getMessage()}";

                // A match SportMonks no longer knows would be retried on every run.
                if ($exception->reason === SportMonksException::NOT_FOUND) {
                    $fixture->forceFill(['details_synced_at' => now()])->save();
                }

                continue;
            }

            $row = $body['data'] ?? null;
            $updated = is_array($row) ? $this->upsertFixture($row) : null;

            if ($updated && in_array($updated->status, MatchState::FINAL_STATUSES, true)) {
                $updated->forceFill(['details_synced_at' => now()])->save();
            }

            $count++;
        }

        return $count;
    }

    public function syncStandings(): int
    {
        $count = 0;

        foreach (League::query()->whereNotNull('current_season_id')->get() as $league) {
            try {
                $body = $this->client->get("standings/season/{$league->current_season_id}");
            } catch (SportMonksException $exception) {
                if ($exception->isFatal()) {
                    throw $exception;
                }

                $this->notes[] = "standings {$league->name}: {$exception->getMessage()}";

                continue;
            }

            foreach ($this->rows($body) as $row) {
                if (! isset($row['team_id']) || ! Team::query()->whereKey($row['team_id'])->exists()) {
                    continue;
                }

                Standing::query()->updateOrCreate(
                    ['season_id' => $row['season_id'] ?? $league->current_season_id, 'team_id' => $row['team_id']],
                    [
                        'league_id' => $league->id,
                        'stage_id' => $row['stage_id'] ?? null,
                        'position' => (int) ($row['position'] ?? 0),
                        'played' => (int) ($row['played'] ?? 0),
                        'won' => (int) ($row['won'] ?? 0),
                        'lost' => (int) ($row['lost'] ?? 0),
                        'draw' => (int) ($row['draw'] ?? 0),
                        'noresult' => (int) ($row['noresult'] ?? 0),
                        'points' => (int) ($row['points'] ?? 0),
                        'net_run_rate' => (float) ($row['netto_run_rate'] ?? 0),
                        'recent_form' => $row['recent_form'] ?? [],
                    ],
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * Squads of the teams that play in the fixture window, a limited number per run.
     */
    public function syncSquads(): int
    {
        $pairs = Fixture::query()
            ->whereNotNull('season_id')
            ->whereBetween('starting_at', [now()->subDays(7), now()->addDays(30)])
            ->get(['local_team_id', 'visitor_team_id', 'season_id'])
            ->flatMap(fn (Fixture $fixture): array => [
                $fixture->local_team_id.'|'.$fixture->season_id => [$fixture->local_team_id, $fixture->season_id],
                $fixture->visitor_team_id.'|'.$fixture->season_id => [$fixture->visitor_team_id, $fixture->season_id],
            ])
            ->unique(fn (array $pair): int => $pair[0])
            ->reject(fn (array $pair): bool => Player::query()
                ->where('team_id', $pair[0])
                ->where('updated_at', '>', now()->subHours(20))
                ->exists())
            ->take((int) config('sportmonks.sync.squads_per_run'));

        $count = 0;

        foreach ($pairs as [$teamId, $seasonId]) {
            try {
                $body = $this->client->get("teams/{$teamId}/squad/{$seasonId}");
            } catch (SportMonksException $exception) {
                if ($exception->isFatal()) {
                    throw $exception;
                }

                $this->notes[] = "squad {$teamId}: {$exception->getMessage()}";

                continue;
            }

            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $squad = $data['squad']['data'] ?? $data['squad'] ?? (array_is_list($data) ? $data : []);

            foreach ($squad as $row) {
                if ($this->upsertPlayer($row, (int) $teamId, true)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Fetch one player's profile and career statistics (used when a player page is opened).
     *
     * @throws SportMonksException
     */
    public function refreshPlayer(Player $player): void
    {
        $body = $this->client->get("players/{$player->id}", ['include' => 'career']);
        $row = $body['data'] ?? null;

        if (! is_array($row)) {
            return;
        }

        $this->upsertPlayer($row, null, false);

        $player->refresh()->forceFill([
            'career' => $this->many($row, 'career'),
            'details_synced_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertTeam(array $row): ?Team
    {
        $id = (int) ($row['id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        $attributes = [
            'name' => (string) ($row['name'] ?? "Team {$id}"),
            'code' => $row['code'] ?? null,
            'image' => $row['image_path'] ?? null,
            'country_id' => $row['country_id'] ?? null,
            'national_team' => (bool) ($row['national_team'] ?? false),
        ];

        $team = Team::query()->find($id);

        if ($team) {
            $team->update($attributes);

            return $team;
        }

        return Team::query()->create($attributes + [
            'id' => $id,
            'slug' => $this->uniqueSlug(Team::class, $attributes['name'], $id),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertVenue(array $row): ?Venue
    {
        $id = (int) ($row['id'] ?? 0);

        if ($id === 0) {
            return null;
        }

        return Venue::query()->updateOrCreate(['id' => $id], [
            'name' => (string) ($row['name'] ?? "Venue {$id}"),
            'city' => $row['city'] ?? null,
            'country_id' => $row['country_id'] ?? null,
            'capacity' => $row['capacity'] ?? null,
            'floodlight' => (bool) ($row['floodlight'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertPlayer(array $row, ?int $teamId, bool $overrideTeam): ?Player
    {
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['fullname'] ?? trim(($row['firstname'] ?? '').' '.($row['lastname'] ?? ''))));

        if ($id === 0 || $name === '') {
            return null;
        }

        $player = Player::query()->find($id) ?? new Player(['id' => $id, 'slug' => $this->uniqueSlug(Player::class, $name, $id)]);
        $position = $row['position'] ?? null;

        $player->fill([
            'fullname' => $name,
            'image' => $row['image_path'] ?? $player->image,
            'date_of_birth' => $row['dateofbirth'] ?? $player->date_of_birth,
            'gender' => $row['gender'] ?? $player->gender,
            'batting_style' => $row['battingstyle'] ?? $player->batting_style,
            'bowling_style' => $row['bowlingstyle'] ?? $player->bowling_style,
            'position' => is_array($position) ? ($position['name'] ?? $player->position) : $player->position,
            'country_id' => $row['country_id'] ?? $player->country_id,
        ]);

        if ($teamId !== null && ($overrideTeam || ! $player->exists)) {
            $player->team_id = $teamId;
        }

        $player->save();

        return $player;
    }

    /**
     * Store a fixture from a fixtures or livescores row, including embedded teams, venue and scoreboards.
     *
     * @param  array<string, mixed>  $row
     */
    private function upsertFixture(array $row): ?Fixture
    {
        $id = (int) ($row['id'] ?? 0);
        $leagueId = (int) ($row['league_id'] ?? 0);

        if ($id === 0 || $leagueId === 0 || ! $this->allowsLeague($leagueId) || empty($row['starting_at'])) {
            return null;
        }

        $local = $this->resolveTeam($row, 'localteam', 'localteam_id');
        $visitor = $this->resolveTeam($row, 'visitorteam', 'visitorteam_id');

        if (! $local || ! $visitor) {
            return null;
        }

        $venue = ($embedded = $this->one($row, 'venue')) ? $this->upsertVenue($embedded) : null;
        $status = (string) ($row['status'] ?? 'NS');

        $attributes = [
            'league_id' => $leagueId,
            'season_id' => $row['season_id'] ?? null,
            'stage_id' => $row['stage_id'] ?? null,
            'round' => $row['round'] ?? null,
            'local_team_id' => $local->id,
            'visitor_team_id' => $visitor->id,
            'venue_id' => $venue?->id ?? $row['venue_id'] ?? null,
            'starting_at' => Carbon::parse($row['starting_at']),
            'type' => $row['type'] ?? null,
            'status' => $status,
            'is_live' => MatchState::isInPlay($status),
            'note' => $row['note'] ?? null,
            'toss_won_team_id' => $row['toss_won_team_id'] ?? null,
            'winner_team_id' => $row['winner_team_id'] ?? null,
            'elected' => $row['elected'] ?? null,
            'man_of_match_id' => $row['man_of_match_id'] ?? null,
        ];

        foreach (['runs', 'batting', 'bowling', 'lineup'] as $scoreboard) {
            if (array_key_exists($scoreboard, $row)) {
                $attributes[$scoreboard] = $this->many($row, $scoreboard);
            }
        }

        $fixture = Fixture::query()->find($id);

        if ($fixture) {
            $fixture->update($attributes);
        } else {
            $fixture = Fixture::query()->create($attributes + [
                'id' => $id,
                'slug' => $this->matchSlug($local, $visitor, $id),
            ]);
        }

        $this->tagLeague($leagueId, $row['type'] ?? null, $local->national_team && $visitor->national_team);

        foreach ($attributes['lineup'] ?? [] as $player) {
            $this->upsertPlayer($player, (int) data_get($player, 'lineup.team_id', 0) ?: null, false);
        }

        return $fixture;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveTeam(array $row, string $include, string $idKey): ?Team
    {
        if ($embedded = $this->one($row, $include)) {
            return $this->upsertTeam($embedded);
        }

        return isset($row[$idKey]) ? Team::query()->find($row[$idKey]) : null;
    }

    /**
     * A league learns its format and international/domestic category from its first fixtures.
     */
    private function tagLeague(int $leagueId, ?string $type, bool $international): void
    {
        League::query()->whereKey($leagueId)->whereNull('format')->update([
            'format' => $type ? Str::limit(Str::before($type, '/'), 20, '') : null,
        ]);

        League::query()->whereKey($leagueId)->whereNull('category')->update([
            'category' => $international ? 'International' : 'Domestic',
        ]);
    }

    private function shouldPollLive(): bool
    {
        return Fixture::query()
            ->where('is_live', true)
            ->orWhereBetween('starting_at', [now()->subHours(12), now()->addMinutes(30)])
            ->exists();
    }

    private function allowsLeague(int $id): bool
    {
        $allowed = config('sportmonks.league_ids');

        return $allowed === [] || in_array($id, $allowed, true);
    }

    private function uniqueSlug(string $model, string $name, int $id): string
    {
        $base = Str::slug($name) ?: 'item';

        return $model::query()->where('slug', $base)->where('id', '!=', $id)->exists() ? "{$base}-{$id}" : $base;
    }

    private function matchSlug(Team $local, Team $visitor, int $id): string
    {
        $short = fn (Team $team): string => Str::slug($team->code ?: $team->name) ?: 'team';

        return "{$short($local)}-vs-{$short($visitor)}-{$id}";
    }

    private function summariseNotes(): ?string
    {
        if ($this->notes === []) {
            return null;
        }

        $extra = count($this->notes) > 3 ? ' (+'.(count($this->notes) - 3).' more)' : '';

        return Str::limit(implode('; ', array_slice($this->notes, 0, 3)).$extra, 500);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $body): array
    {
        $data = $body['data'] ?? [];

        return is_array($data) && array_is_list($data) ? $data : [];
    }

    /**
     * A related collection, whether SportMonks wraps it in {"data": [...]} or not.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, array<string, mixed>>
     */
    private function many(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $value = $value['data'] ?? $value;

        return array_is_list($value) ? $value : [];
    }

    /**
     * A related single object, whether SportMonks wraps it in {"data": {...}} or not.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function one(array $row, string $key): ?array
    {
        $value = $row[$key] ?? null;

        if (! is_array($value) || $value === []) {
            return null;
        }

        $value = $value['data'] ?? $value;

        return array_is_list($value) ? ($value[0] ?? null) : $value;
    }
}
