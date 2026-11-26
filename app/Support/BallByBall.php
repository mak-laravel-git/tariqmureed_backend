<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Reading a match's deliveries. The data feed sends no written commentary, only what happened on every ball, so
 * the sentences here are put together from that.
 */
final class BallByBall
{
    /**
     * Keep only what the site shows of a delivery: the feed repeats both players' full profiles on every ball.
     *
     * @param  array<string, mixed>  $ball
     * @return array<string, mixed>
     */
    public static function compact(array $ball): array
    {
        $score = (array) (data_get($ball, 'score.data') ?? data_get($ball, 'score') ?? []);
        $name = fn (string $key): string => (string) (data_get($ball, "{$key}.fullname") ?? data_get($ball, "{$key}.data.fullname") ?? '');

        return [
            'id' => (int) ($ball['id'] ?? 0),
            'board' => (string) ($ball['scoreboard'] ?? 'S1'),
            'over' => number_format((float) ($ball['ball'] ?? 0), 1, '.', ''),
            'team' => (int) ($ball['team_id'] ?? 0),
            'batter' => $name('batsman'),
            'bowler' => $name('bowler'),
            'result' => (string) ($score['name'] ?? ''),
            'runs' => (int) ($score['runs'] ?? 0),
            'byes' => (int) ($score['bye'] ?? 0),
            'legByes' => (int) ($score['leg_bye'] ?? 0),
            'noBalls' => (int) ($score['noball'] ?? 0),
            'noBallRuns' => (int) ($score['noball_runs'] ?? 0),
            'four' => (bool) ($score['four'] ?? false),
            'six' => (bool) ($score['six'] ?? false),
            'wicket' => (bool) (($score['is_wicket'] ?? false) || ($score['out'] ?? false)),
            'legal' => (bool) ($score['ball'] ?? true),
        ];
    }

    /**
     * Every run the delivery added to the total.
     *
     * @param  array<string, mixed>  $ball
     */
    public static function total(array $ball): int
    {
        return $ball['runs'] + $ball['byes'] + $ball['legByes'] + $ball['noBalls'] + $ball['noBallRuns'];
    }

    /**
     * The short mark shown in the circle beside a delivery: "W", "6", "4", "Wd", "Nb", a number or "•".
     *
     * @param  array<string, mixed>  $ball
     */
    public static function mark(array $ball): string
    {
        $result = strtolower($ball['result']);

        return match (true) {
            $ball['wicket'] => 'W',
            $ball['six'] => '6',
            $ball['four'] => '4',
            $ball['noBalls'] > 0 || str_contains($result, 'no ball') => 'Nb',
            ! $ball['legal'] && str_contains($result, 'wide') => 'Wd',
            self::total($ball) === 0 => '•',
            default => (string) self::total($ball),
        };
    }

    /**
     * "Coles to Marsh, FOUR"
     *
     * @param  array<string, mixed>  $ball
     */
    public static function sentence(array $ball): string
    {
        $what = match (true) {
            $ball['wicket'] => 'OUT! '.$ball['result'],
            $ball['six'] => 'SIX',
            $ball['four'] => 'FOUR',
            default => strtolower($ball['result'] ?: self::total($ball).' runs'),
        };

        $who = trim(($ball['bowler'] ?: 'Bowler').' to '.($ball['batter'] ?: 'batter'));

        return "{$who}, {$what}";
    }

    /**
     * Runs and legal balls since the last wicket of the innings.
     *
     * @param  Collection<int, array<string, mixed>>  $innings  deliveries of one innings, in order
     * @return array{runs: int, balls: int}
     */
    public static function partnership(Collection $innings): array
    {
        $lastWicket = $innings->values()->reverse()->search(fn (array $ball): bool => $ball['wicket']);
        $since = $lastWicket === false ? $innings->values() : $innings->values()->slice($lastWicket + 1);

        return [
            'runs' => $since->sum(fn (array $ball): int => self::total($ball)),
            'balls' => $since->where('legal', true)->count(),
        ];
    }

    /**
     * Legal deliveries off which nothing at all was scored.
     *
     * @param  Collection<int, array<string, mixed>>  $balls
     */
    public static function dotBalls(Collection $balls): int
    {
        return $balls->filter(fn (array $ball): bool => $ball['legal'] && $ball['runs'] + $ball['byes'] + $ball['legByes'] === 0)->count();
    }
}
