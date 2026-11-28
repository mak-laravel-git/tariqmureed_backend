<?php

namespace App\Support;

use App\Models\Fixture;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which live matches and results the home page's "Live Match Center" puts first: the competitions arranged in the
 * admin dashboard in their order (a list of league ids in the `settings` table), then the other internationals,
 * then the rest. Upcoming matches are not ranked: they are listed by date and time.
 */
final class MatchPriority
{
    /** Days back within which an important competition's result goes before a newer one. */
    public const WINDOW_DAYS = 7;

    private const KEY = 'liveCentreOrder';

    /**
     * The league ids arranged in the dashboard, the most important first.
     *
     * @return array<int, int>
     */
    public static function chosen(): array
    {
        $stored = json_decode((string) Setting::query()->where('key', self::KEY)->value('value'), true);

        return is_array($stored) ? array_values(array_map('intval', $stored)) : [];
    }

    /**
     * @param  array<int, int|string>  $ids  An empty list leaves only "internationals first".
     */
    public static function save(array $ids): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => json_encode(array_values(array_map('intval', $ids)))],
        );
    }

    /**
     * Orders matches by how important their competition is. Order by time afterwards.
     *
     * @param  Builder<Fixture>  $matches
     */
    public static function rank(Builder $matches): void
    {
        $cases = '';
        $bindings = [];
        $chosen = self::chosen();

        foreach ($chosen as $position => $leagueId) {
            $cases .= " when league_id = ? then {$position}";
            $bindings[] = $leagueId;
        }

        $international = count($chosen);
        $others = $international + 1;
        $cases .= " when league_id in (select id from leagues where category = ?) then {$international}";
        $bindings[] = 'International';

        $matches->orderByRaw("case{$cases} else {$others} end", $bindings);
    }

    /**
     * The first results: those of the past week by importance and then newest first, topped up with older ones
     * by time alone.
     *
     * @param  Builder<Fixture>  $results  Filtered and not yet ordered.
     * @return Collection<int, Fixture>
     */
    public static function takeResults(Builder $results, int $limit): Collection
    {
        $edge = now()->subDays(self::WINDOW_DAYS);

        $recent = (clone $results)->where('starting_at', '>=', $edge);
        self::rank($recent);
        $first = $recent->orderByDesc('starting_at')->limit($limit)->get();

        if ($first->count() >= $limit) {
            return $first;
        }

        $older = $results
            ->where('starting_at', '<', $edge)
            ->orderByDesc('starting_at')
            ->limit($limit - $first->count())
            ->get();

        return $first->concat($older);
    }
}
