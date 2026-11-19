<?php

namespace App\Support;

use App\Models\Fixture;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Derives a series' status (in progress, upcoming, completed) and date range from its fixtures.
 */
final class LeagueSchedules
{
    /**
     * @param  array<int, int>|null  $leagueIds
     * @return Collection<int, array{first: CarbonInterface, last: CarbonInterface, count: int, status: string, group: string}>
     */
    public static function all(?array $leagueIds = null): Collection
    {
        return Fixture::query()
            ->selectRaw('league_id, min(starting_at) as first_at, max(starting_at) as last_at, count(*) as total, max(is_live) as live')
            ->when($leagueIds !== null, fn ($query) => $query->whereIn('league_id', $leagueIds))
            ->groupBy('league_id')
            ->get()
            ->mapWithKeys(function (Fixture $row): array {
                $first = Date::parse($row->getAttribute('first_at'));
                $last = Date::parse($row->getAttribute('last_at'));
                $inProgress = (bool) $row->getAttribute('live') || ($first->isPast() && $last->isFuture());

                [$status, $group] = match (true) {
                    $inProgress => ['In progress', 'current'],
                    $first->isFuture() => ['Upcoming', 'upcoming'],
                    default => ['Completed', 'completed'],
                };

                return [(int) $row->league_id => [
                    'first' => $first,
                    'last' => $last,
                    'count' => (int) $row->getAttribute('total'),
                    'status' => $status,
                    'group' => $group,
                ]];
            });
    }

    /**
     * "12 Jan – 7 Feb 2026"
     */
    public static function dateRange(CarbonInterface $first, CarbonInterface $last): string
    {
        $timezone = (string) config('sportmonks.display_timezone');
        $first = $first->setTimezone($timezone);
        $last = $last->setTimezone($timezone);

        return $first->year === $last->year
            ? $first->format('j M').' – '.$last->format('j M Y')
            : $first->format('j M Y').' – '.$last->format('j M Y');
    }
}
