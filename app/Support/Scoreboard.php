<?php

namespace App\Support;

use App\Models\Fixture;

/**
 * Helpers that turn the raw SportMonks "runs" rows of a fixture into the score
 * strings the website shows ("312 & 150/4", "15.2").
 */
final class Scoreboard
{
    /**
     * Innings of one team, ordered S1, S2, ...
     *
     * @return array<int, array<string, mixed>>
     */
    public static function innings(Fixture $fixture, int $teamId): array
    {
        return collect($fixture->runs ?? [])
            ->filter(fn (array $row): bool => (int) ($row['team_id'] ?? 0) === $teamId)
            ->sortBy(fn (array $row): int => self::number($row['inning'] ?? null))
            ->values()
            ->all();
    }

    /**
     * "S2" or 2 become 2; anything without a digit counts as the first innings.
     */
    public static function number(mixed $label): int
    {
        return (int) preg_replace('/\D+/', '', (string) $label) ?: 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $innings
     */
    public static function score(array $innings): ?string
    {
        $parts = collect($innings)
            ->filter(fn (array $row): bool => isset($row['score']))
            ->map(fn (array $row): string => $row['score'].(isset($row['wickets']) ? '/'.$row['wickets'] : ''));

        return $parts->isEmpty() ? null : $parts->implode(' & ');
    }

    /**
     * Overs bowled in the team's most recent innings.
     *
     * @param  array<int, array<string, mixed>>  $innings
     */
    public static function overs(array $innings): ?string
    {
        $last = collect($innings)->last();

        return isset($last['overs']) ? (string) $last['overs'] : null;
    }

    /**
     * "15.2" overs is 15 overs and 2 balls, so 92 balls.
     */
    public static function balls(string|float|int|null $overs): int
    {
        [$whole, $balls] = array_pad(explode('.', (string) $overs), 2, '0');

        return ((int) $whole * 6) + (int) $balls;
    }

    public static function runRate(int $runs, string|float|int|null $overs): ?string
    {
        $balls = self::balls($overs);

        return $balls > 0 ? number_format($runs / $balls * 6, 2, '.', '') : null;
    }
}
