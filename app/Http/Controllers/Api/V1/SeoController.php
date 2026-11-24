<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Fixture;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use App\Models\Writer;
use App\Support\SeoPages;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;

class SeoController extends Controller
{
    /**
     * Everything the website needs to write its <head>: the global SEO settings and the text of each page.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'settings' => SiteSettings::public(),
            'pages' => SeoPages::resolved(),
        ]]);
    }

    /**
     * The addresses for sitemap.xml: stories search engines may index, plus the profile, team, series and match pages.
     */
    public function sitemap(): JsonResponse
    {
        $slugs = fn (string $model, int $limit = 2000): array => $model::query()->orderBy('slug')->limit($limit)->pluck('slug')->all();

        return response()->json(['data' => [
            'articles' => Article::query()
                ->visible()
                ->where('noindex', false)
                ->latest('published_at')
                ->limit(5000)
                ->get(['slug', 'updated_at', 'published_at'])
                ->map(fn (Article $article): array => [
                    'slug' => $article->slug,
                    'updatedAt' => $article->updated_at->toIso8601String(),
                    'publishedAt' => $article->published_at?->toIso8601String(),
                ])
                ->values(),
            'writers' => $slugs(Writer::class),
            'teams' => $slugs(Team::class),
            'players' => $slugs(Player::class),
            'series' => $slugs(League::class),
            // Matches from the last 30 days and the next 30 days: older scorecards are not worth a crawl budget.
            'matches' => Fixture::query()
                ->whereBetween('starting_at', [now()->subDays(30), now()->addDays(30)])
                ->orderBy('starting_at')
                ->limit(2000)
                ->pluck('slug')
                ->all(),
        ]]);
    }
}
