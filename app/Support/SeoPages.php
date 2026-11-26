<?php

namespace App\Support;

use App\Models\SeoPage;

/**
 * The website pages whose search and share text can be edited in the admin SEO screen.
 *
 * The default title and description are the ones the website already uses (kept in step with each page's
 * metadata in the Next.js app), shown in the editor as the starting text.
 */
final class SeoPages
{
    /**
     * @return array<string, array{label: string, title: string, description: string}>
     */
    public static function catalog(): array
    {
        return [
            '/' => ['label' => 'Home', 'title' => 'TMN Sport — Live cricket scores, news, stats and video', 'description' => 'Live cricket scores, breaking news, analysis, statistics and video from every major international and franchise competition.'],
            '/news' => ['label' => 'News', 'title' => 'Latest News', 'description' => 'Breaking stories, sharp analysis and original reporting from across the world of cricket.'],
            '/live-scores' => ['label' => 'Live Scores', 'title' => 'Live Scores', 'description' => 'Follow matches live with current scores, key moments and verified status updates.'],
            '/schedule' => ['label' => 'Match Schedule', 'title' => 'Match Schedule', 'description' => 'Plan your week with upcoming fixtures, start times and venue information.'],
            '/series' => ['label' => 'Series & Tournaments', 'title' => 'Series & Tournaments', 'description' => 'Explore current, upcoming and recently completed competitions.'],
            '/teams' => ['label' => 'Teams', 'title' => 'Teams', 'description' => 'National sides, domestic clubs, squads, fixtures and results.'],
            '/players' => ['label' => 'Players', 'title' => 'Players', 'description' => 'Profiles and career statistics for the players making headlines.'],
            '/rankings' => ['label' => 'Rankings', 'title' => 'Cricket Rankings', 'description' => 'Official team rankings in Tests, ODIs and T20Is, with the leading batters and bowlers of every team.'],
            '/stats' => ['label' => 'Stats', 'title' => 'Statistics', 'description' => 'Batting and bowling tables by format and scope, taken straight from the data feed.'],
            '/records' => ['label' => 'Records', 'title' => 'Cricket Records', 'description' => 'Landmark performances across batting, bowling, teams and tournaments.'],
            '/videos' => ['label' => 'Videos', 'title' => 'Videos', 'description' => 'Highlights, interviews, analysis and original TMN Sport features.'],
        ];
    }

    /**
     * What the website should use for every page: the catalogue text with the admin's edits on top.
     *
     * @return array<string, array{title: string, description: string, image: string, noindex: bool}>
     */
    public static function resolved(): array
    {
        $saved = SeoPage::query()->get()->keyBy('path');
        $pages = [];

        foreach (self::catalog() as $path => $page) {
            $row = $saved->get($path);

            $pages[$path] = [
                'title' => filled($row?->title) ? (string) $row->title : $page['title'],
                'description' => filled($row?->description) ? (string) $row->description : $page['description'],
                'image' => (string) ($row?->image ?? ''),
                'noindex' => (bool) ($row?->noindex ?? false),
            ];
        }

        return $pages;
    }
}
