<?php

namespace App\Support;

use App\Models\Article;
use App\Models\Player;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The players the website calls "trending": those named in recent stories come first (most stories first),
 * and the leading run-scorers fill the places that are left. Only players with a real photo and batting
 * figures in an international one-day or T20 format are listed.
 */
final class TrendingPlayers
{
    public const LIMIT = 4;

    /** How far back a story still makes a player part of the news. */
    private const NEWS_DAYS = 30;

    /** The format shown on a card: the first one the player has batting figures in. */
    private const FORMATS = ['ODI', 'T20I'];

    /**
     * @return array<int, array{id: int, slug: string, name: string, image: string, role: string, team: string, country: string, rankLabel: string, matches: int, runs: int, average: float}>
     */
    public static function list(): array
    {
        $stories = self::recentStories();
        $players = [];

        // Career records are large, so players are read in batches and only the card is kept.
        Player::query()
            ->select(['id', 'team_id', 'slug', 'fullname', 'image', 'position', 'career'])
            ->with('team:id,name,national_team')
            ->whereNotNull('career')
            ->whereNotNull('image')
            ->where('image', '!=', '')
            // SportMonks gives players without a photo a blank "placeholder" picture.
            ->where('image', 'not like', '%placeholder%')
            // Careers with neither format are not worth fetching.
            ->where(function (Builder $query): void {
                foreach (self::FORMATS as $format) {
                    $query->orWhere('career', 'like', "%{$format}%");
                }
            })
            ->lazyById(1000)
            ->each(function (Player $player) use ($stories, &$players): void {
                $figures = self::figures($player->career ?? []);

                if ($figures === null) {
                    return;
                }

                $players[] = [
                    'mentions' => self::mentions($player->fullname, $stories),
                    'card' => self::card($player, $figures),
                ];
            });

        return collect($players)
            ->sortBy([['mentions', 'desc'], ['card.runs', 'desc'], ['card.id', 'asc']])
            ->take(self::LIMIT)
            ->pluck('card')
            ->values()
            ->all();
    }

    /**
     * The text of every story published in the news window, in lower case.
     *
     * @return Collection<int, string>
     */
    private static function recentStories(): Collection
    {
        return Article::query()
            ->visible()
            ->where('published_at', '>=', now()->subDays(self::NEWS_DAYS))
            ->get(['title', 'summary', 'body', 'tags'])
            ->map(fn (Article $article): string => Str::lower(implode("\n", [$article->title, $article->summary, $article->body, $article->tags])));
    }

    /**
     * How many of the stories name the player in full.
     *
     * @param  Collection<int, string>  $stories
     */
    private static function mentions(string $name, Collection $stories): int
    {
        $needle = Str::lower(trim($name));
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

        return $stories
            ->filter(fn (string $story): bool => str_contains($story, $needle) && preg_match($pattern, $story) === 1)
            ->count();
    }

    /**
     * Career rows are stored per season and format; these are the batting totals of one format.
     *
     * @param  array<int, array<string, mixed>>  $career
     * @return array{format: string, matches: int, runs: int, average: float}|null
     */
    private static function figures(array $career): ?array
    {
        $byFormat = collect($career)->groupBy(fn (array $row): string => strtoupper((string) ($row['type'] ?? '')));

        foreach (self::FORMATS as $format) {
            $rows = $byFormat->get($format, collect());
            $runs = $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.runs_scored', 0));

            if ($runs === 0) {
                continue;
            }

            $dismissals = $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.innings', 0) - (int) data_get($row, 'batting.not_outs', 0));

            return [
                'format' => $format,
                'matches' => $rows->sum(fn (array $row): int => (int) data_get($row, 'batting.matches', 0)),
                'runs' => $runs,
                // A batter who was never out has no average; the runs stand in for it.
                'average' => round($runs / max($dismissals, 1), 2),
            ];
        }

        return null;
    }

    /**
     * One player in the shape of the website's card. A national side is shown as the country,
     * any other side as the team, so the same name is never printed twice.
     *
     * @param  array{format: string, matches: int, runs: int, average: float}  $figures
     * @return array{id: int, slug: string, name: string, image: string, role: string, team: string, country: string, rankLabel: string, matches: int, runs: int, average: float}
     */
    private static function card(Player $player, array $figures): array
    {
        $isNational = (bool) $player->team?->national_team;
        $teamName = $player->team->name ?? '';

        return [
            'id' => $player->id,
            'slug' => $player->slug,
            'name' => $player->fullname,
            'image' => (string) $player->image,
            'role' => $player->position ?? 'Player',
            'team' => $isNational ? '' : $teamName,
            'country' => $isNational ? $teamName : '',
            'rankLabel' => $figures['format'],
            'matches' => $figures['matches'],
            'runs' => $figures['runs'],
            'average' => $figures['average'],
        ];
    }
}
