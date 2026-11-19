<?php

namespace App\Enums;

/**
 * Match state the website understands, derived from a SportMonks fixture status.
 *
 * @see https://docs.sportmonks.com/v2/cricket-api/statuses-and-definitions
 */
enum MatchState: string
{
    case LIVE = 'live';
    case BREAK = 'break';
    case UPCOMING = 'upcoming';
    case RESULT = 'result';

    /**
     * Statuses of a match that is being played right now.
     *
     * @var array<int, string>
     */
    public const LIVE_STATUSES = ['1st Innings', '2nd Innings', '3rd Innings', '4th Innings'];

    /**
     * Statuses of a match that is under way but paused (innings break, meal break, stumps, weather).
     *
     * @var array<int, string>
     */
    public const BREAK_STATUSES = [
        'Innings Break', 'Tea Break', 'Lunch', 'Dinner', 'Int.',
        'Stump Day 1', 'Stump Day 2', 'Stump Day 3', 'Stump Day 4',
    ];

    /**
     * Statuses of a match that will not be played any further.
     *
     * @var array<int, string>
     */
    public const FINAL_STATUSES = ['Finished', 'Cancl.', 'Aban.'];

    public static function fromStatus(?string $status): self
    {
        return match (true) {
            in_array($status, self::LIVE_STATUSES, true) => self::LIVE,
            in_array($status, self::BREAK_STATUSES, true) => self::BREAK,
            in_array($status, self::FINAL_STATUSES, true) => self::RESULT,
            default => self::UPCOMING,
        };
    }

    public static function isInPlay(?string $status): bool
    {
        return in_array($status, [...self::LIVE_STATUSES, ...self::BREAK_STATUSES], true);
    }
}
