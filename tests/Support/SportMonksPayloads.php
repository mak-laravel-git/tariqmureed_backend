<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * SportMonks Cricket API v2 response fragments for tests, following the field names in the docs.
 */
final class SportMonksPayloads
{
    /**
     * Fake SportMonks answers by path (relative to the v2.0 base URL). Earlier entries win,
     * so list specific paths such as "fixtures/20094" before "fixtures".
     *
     * @param  array<string, mixed>  $responses  Arrays become JSON 200 responses; other values are used as given.
     */
    public static function fake(array $responses): void
    {
        $stubs = [];

        foreach ($responses as $path => $body) {
            $stubs["cricket.sportmonks.com/api/v2.0/{$path}*"] = is_array($body) ? Http::response($body) : $body;
        }

        Http::fake($stubs);
    }

    /**
     * @return array<string, mixed>
     */
    public static function team(int $id, string $name, string $code, bool $national = true): array
    {
        return [
            'resource' => 'teams',
            'id' => $id,
            'name' => $name,
            'code' => $code,
            'image_path' => "https://cdn.sportmonks.com/images/cricket/teams/{$id}.png",
            'country_id' => 1,
            'national_team' => $national,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function league(int $id = 3, string $name = 'Test Series', int $seasonId = 6): array
    {
        return [
            'resource' => 'leagues',
            'id' => $id,
            'season_id' => $seasonId,
            'country_id' => 98,
            'name' => $name,
            'code' => 'TS',
            'image_path' => "https://cdn.sportmonks.com/images/cricket/leagues/{$id}.png",
            'type' => 'international',
            'seasons' => ['data' => [
                ['resource' => 'seasons', 'id' => $seasonId, 'league_id' => $id, 'name' => '2026'],
                ['resource' => 'seasons', 'id' => $seasonId - 1, 'league_id' => $id, 'name' => '2025'],
            ]],
        ];
    }

    /**
     * An upcoming India v Australia fixture with embedded teams and venue.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function fixture(array $overrides = []): array
    {
        return array_replace([
            'resource' => 'fixtures',
            'id' => 20094,
            'league_id' => 3,
            'season_id' => 6,
            'stage_id' => 1,
            'round' => '2nd T20I',
            'localteam_id' => 40,
            'visitorteam_id' => 41,
            'starting_at' => now()->addDay()->toIso8601String(),
            'type' => 'T20I',
            'live' => false,
            'status' => 'NS',
            'note' => null,
            'venue_id' => 96,
            'toss_won_team_id' => null,
            'winner_team_id' => null,
            'elected' => null,
            'localteam' => ['data' => self::team(40, 'India', 'IND')],
            'visitorteam' => ['data' => self::team(41, 'Australia', 'AUS')],
            'venue' => ['data' => ['id' => 96, 'name' => 'Wankhede Stadium', 'city' => 'Mumbai', 'country_id' => 1, 'capacity' => 33000, 'floodlight' => true]],
            'runs' => [],
        ], $overrides);
    }

    /**
     * India batting in the first innings against Australia, with scoreboards and lineups.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function liveFixture(array $overrides = []): array
    {
        return self::fixture(array_replace([
            'status' => '1st Innings',
            'live' => true,
            'starting_at' => now()->subHour()->toIso8601String(),
            'note' => 'India need to build a platform',
            'toss_won_team_id' => 40,
            'elected' => 'batting',
            'runs' => [
                ['team_id' => 40, 'inning' => 1, 'score' => 148, 'wickets' => 3, 'overs' => 15.2],
            ],
            'batting' => [
                ['id' => 1, 'sort' => 1, 'team_id' => 40, 'active' => false, 'scoreboard' => 'S1', 'player_id' => 12, 'ball' => 10, 'score' => 8, 'four_x' => 1, 'six_x' => 0, 'rate' => 80, 'batsmanout_id' => 12, 'bowling_player_id' => 21, 'catch_stump_player_id' => 22, 'wicket_id' => 54],
                ['id' => 2, 'sort' => 2, 'team_id' => 40, 'active' => true, 'scoreboard' => 'S1', 'player_id' => 11, 'ball' => 20, 'score' => 35, 'four_x' => 4, 'six_x' => 1, 'rate' => 175, 'batsmanout_id' => null, 'bowling_player_id' => null, 'catch_stump_player_id' => null],
            ],
            'bowling' => [
                ['id' => 1, 'team_id' => 41, 'active' => true, 'scoreboard' => 'S1', 'player_id' => 21, 'overs' => 3, 'medians' => 0, 'runs' => 24, 'wickets' => 1, 'rate' => 8],
            ],
            'lineup' => ['data' => [
                ['id' => 11, 'fullname' => 'Rohit Sharma', 'image_path' => 'https://cdn.sportmonks.com/p/11.png', 'battingstyle' => 'right-hand-bat', 'position' => ['id' => 1, 'name' => 'Batsman'], 'lineup' => ['team_id' => 40, 'captain' => true, 'wicketkeeper' => false]],
                ['id' => 12, 'fullname' => 'Virat Kohli', 'lineup' => ['team_id' => 40, 'captain' => false, 'wicketkeeper' => false]],
                ['id' => 21, 'fullname' => 'Pat Cummins', 'lineup' => ['team_id' => 41, 'captain' => true, 'wicketkeeper' => false]],
                ['id' => 22, 'fullname' => 'Steve Smith', 'lineup' => ['team_id' => 41, 'captain' => false, 'wicketkeeper' => false]],
            ]],
        ], $overrides));
    }
}
