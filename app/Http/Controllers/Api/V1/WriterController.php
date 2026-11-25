<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ArticleResource;
use App\Http\Resources\Api\V1\WriterResource;
use App\Models\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WriterController extends Controller
{
    /**
     * Active authors with at least one published story. 404 while there are none, so the sample authors stay.
     */
    public function index(): AnonymousResourceCollection
    {
        $writers = Writer::query()
            ->where('status', 'Active')
            ->whereHas('articles', fn ($query) => $query->visible())
            ->withCount(['articles as visible_articles_count' => fn ($query) => $query->visible()])
            ->orderByDesc('visible_articles_count')
            ->get();

        abort_if($writers->isEmpty(), 404);

        return WriterResource::collection($writers);
    }

    public function show(string $slug): WriterResource
    {
        return new WriterResource(Writer::query()->where('status', 'Active')->where('slug', $slug)->firstOrFail());
    }

    /**
     * Every published story of one author, newest first. A story appears here as soon as it goes live.
     */
    public function articles(Request $request, string $slug): JsonResponse
    {
        $writer = Writer::query()->where('status', 'Active')->where('slug', $slug)->firstOrFail();
        $limit = min(max($request->integer('limit', 9), 1), 60);

        $stories = $writer->articles()
            ->visible()
            ->with(['category', 'writer'])
            ->latest('published_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        return response()->json(['data' => [
            'articles' => ArticleResource::collection($stories)->resolve($request),
            'total' => $writer->articles()->visible()->count(),
        ]]);
    }
}
