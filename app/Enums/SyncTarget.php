<?php

namespace App\Enums;

/**
 * One kind of SportMonks data the application keeps in its own database.
 */
enum SyncTarget: string
{
    case LEAGUES = 'leagues';
    case VENUES = 'venues';
    case TEAMS = 'teams';
    case FIXTURES = 'fixtures';
    case SQUADS = 'squads';
    case STANDINGS = 'standings';
    case DETAILS = 'details';
    case RANKINGS = 'rankings';
    case CAREERS = 'careers';
    case LIVE = 'live';
    case ALL = 'all';

    /**
     * Targets that make up a full sync, in dependency order.
     *
     * @return array<int, self>
     */
    public static function fullSync(): array
    {
        return [self::LEAGUES, self::VENUES, self::TEAMS, self::FIXTURES, self::SQUADS, self::STANDINGS, self::DETAILS, self::RANKINGS];
    }

    public function label(): string
    {
        return match ($this) {
            self::LEAGUES => 'Leagues & seasons',
            self::VENUES => 'Venues',
            self::TEAMS => 'Team profiles',
            self::FIXTURES => 'Fixtures & results',
            self::SQUADS => 'Squads & players',
            self::STANDINGS => 'League standings',
            self::DETAILS => 'Scorecards',
            self::RANKINGS => 'ICC team rankings',
            self::CAREERS => 'Player career statistics',
            self::LIVE => 'Live scores',
            self::ALL => 'Full sync',
        };
    }
}
