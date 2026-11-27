<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

/**
 * The teams in the home page's "Team Fixtures" section: the ones chosen in the admin dashboard, kept as a list
 * of ids in the `settings` table. Until some are chosen, teams with fixtures are picked automatically.
 */
final class HomeTeams
{
    public const LIMIT = 10;

    private const KEY = 'homeTeams';

    /**
     * What the website shows, in order.
     *
     * @return Collection<int, Team>
     */
    public static function list(): Collection
    {
        $selected = self::selected();

        return $selected->isNotEmpty() ? $selected : self::automatic();
    }

    /**
     * The teams chosen in the dashboard, in the chosen order.
     *
     * @return Collection<int, Team>
     */
    public static function selected(): Collection
    {
        $stored = json_decode((string) Setting::query()->where('key', self::KEY)->value('value'), true);
        $ids = is_array($stored) ? array_slice($stored, 0, self::LIMIT) : [];

        if ($ids === []) {
            return new Collection;
        }

        $teams = Team::query()->whereKey($ids)->get()->keyBy('id');

        return new Collection(collect($ids)->map(fn (int $id): ?Team => $teams->get($id))->filter()->values()->all());
    }

    /**
     * @param  array<int, int|string>  $ids  An empty list goes back to the automatic choice.
     */
    public static function save(array $ids): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => json_encode(array_values(array_map('intval', $ids)))],
        );
    }

    /**
     * Teams with fixtures: national sides first, then domestic ones, each A to Z.
     *
     * @return Collection<int, Team>
     */
    private static function automatic(): Collection
    {
        return Team::query()
            ->playing()
            // SportMonks lists matches whose sides are not decided yet under a team called "TBC".
            ->where('name', '!=', 'TBC')
            ->orderByDesc('national_team')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();
    }
}
