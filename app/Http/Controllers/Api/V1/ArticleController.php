<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ArticleDetailResource;
use App\Http\Resources\Api\V1\ArticleResource;
use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ArticleController extends Controller
{
    /**
     * The lead story and the four stories beside it (home page "Featured Stories").
     */
    public function featured(Request $request): JsonResponse
    {
        $stories = $this->newest()->limit(5)->get();

        abort_if($stories->isEmpty(), 404);

        return response()->json(['data' => [
            'lead' => (new ArticleResource($stories->first()))->resolve($request),
            'side' => ArticleResource::collection($stories->slice(1)->values())->resolve($request),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->abortWithoutStories();

        return ArticleResource::collection($this->newest()->limit(min(max($request->integer('per_page', 9), 1), 50))->get());
    }

    public function topHeadlines(): AnonymousResourceCollection
    {
        $this->abortWithoutStories();

        return ArticleResource::collection($this->newest()->limit(5)->get());
    }

    public function mostRead(): AnonymousResourceCollection
    {
        $this->abortWithoutStories();

        return ArticleResource::collection($this->stories()->orderByDesc('views')->latest('published_at')->limit(5)->get());
    }

    public function show(string $slug): ArticleDetailResource
    {
        $article = $this->stories()->where('slug', $slug)->firstOrFail();

        $article->increment('views');

        return new ArticleDetailResource($article);
    }

    /**
     * @return Builder<Article>
     */
    private function stories(): Builder
    {
        return Article::query()->visible()->with(['category', 'writer']);
    }

    /**
     * @return Builder<Article>
     */
    private function newest(): Builder
    {
        return $this->stories()->latest('published_at');
    }

    /**
     * Until the first story is published the site keeps showing its sample stories (the frontend treats 404 as "no data").
     */
    private function abortWithoutStories(): void
    {
        abort_unless(Article::query()->visible()->exists(), 404);
    }
}
