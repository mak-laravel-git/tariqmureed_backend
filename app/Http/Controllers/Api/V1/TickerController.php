<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\JsonResponse;

class TickerController extends Controller
{
    /**
     * The breaking-news bar: stories marked "Breaking News" in the dashboard, newest first. 404 while there are none,
     * so the site keeps its sample ticker until the first one is marked.
     */
    public function __invoke(): JsonResponse
    {
        $items = Article::query()
            ->visible()
            ->where('is_breaking', true)
            ->latest('published_at')
            ->limit(8)
            ->get(['id', 'title', 'slug'])
            ->map(fn (Article $article): array => ['id' => $article->id, 'text' => $article->title, 'href' => '/news/'.$article->slug]);

        abort_if($items->isEmpty(), 404);

        return response()->json(['data' => $items]);
    }
}
