<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ArticleResource;
use App\Models\Article;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NewsController extends Controller
{
    /**
     * The news page: newest story on top, the rest as a list, plus the category filter.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Article::query()->visible()->exists(), 404);

        $limit = min(max($request->integer('limit', 12), 1), 60);
        $matching = Article::query()
            ->visible()
            ->with(['category', 'writer'])
            ->when($request->filled('category'), fn ($query) => $query->whereHas('category', fn ($category) => $category->where('slug', $request->query('category'))));

        $total = (clone $matching)->count();
        $stories = $matching->latest('published_at')->limit($limit + 1)->get();
        $featured = $stories->first();

        return response()->json(['data' => [
            'featured' => $featured ? (new ArticleResource($featured))->resolve($request) : null,
            'articles' => ArticleResource::collection($stories->slice(1, $limit)->values())->resolve($request),
            'categories' => Category::query()
                ->whereHas('articles', fn ($query) => $query->visible())
                ->orderBy('name')
                ->get(['slug', 'name'])
                ->map(fn (Category $category): array => ['slug' => $category->slug, 'name' => $category->name])
                ->all(),
            'total' => max($total - 1, 0),
        ]]);
    }
}
