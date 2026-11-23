<?php

namespace App\Support;

/**
 * Every place on the public website that can show an advertisement. The keys are used by the Next.js site
 * (<AdSlot placement="...">), so a key must never be renamed without changing the site too.
 */
final class AdPlacements
{
    /** @var array<string, array{label: string, size: string}> */
    private const ALL = [
        'home-top' => ['label' => 'Home page: top banner', 'size' => '970 x 90'],
        'home-sidebar-top' => ['label' => 'Home page: Latest News sidebar, first box', 'size' => '300 x 250'],
        'home-sidebar-bottom' => ['label' => 'Home page: Latest News sidebar, second box', 'size' => '300 x 250'],
        'home-stats' => ['label' => 'Home page: Stats section sidebar', 'size' => '300 x 250'],
        'home-team' => ['label' => 'Home page: Team fixtures box', 'size' => '300 x 250'],
        'home-bottom' => ['label' => 'Home page: bottom banner above the footer', 'size' => '728 x 90'],
        'news-sidebar' => ['label' => 'News page: sidebar', 'size' => '320 x 128'],
        'article-sidebar' => ['label' => 'Article page: sidebar above related stories', 'size' => '320 x 128'],
        'match-sidebar' => ['label' => 'Match page: below the match details', 'size' => '320 x 128'],
        'series-sidebar' => ['label' => 'Series page: sidebar', 'size' => '320 x 128'],
        'team-sidebar' => ['label' => 'Team page: sidebar', 'size' => '320 x 128'],
        'legal-sidebar' => ['label' => 'Privacy, Cookie and Terms pages: sidebar', 'size' => '300 x 250'],
    ];

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * The placements as the admin form lists them.
     *
     * @return array<int, array{key: string, label: string, size: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::ALL as $key => $placement) {
            $options[] = ['key' => $key, ...$placement];
        }

        return $options;
    }

    public static function label(?string $key): string
    {
        return $key !== null && isset(self::ALL[$key]) ? self::ALL[$key]['label'] : (string) $key;
    }
}
