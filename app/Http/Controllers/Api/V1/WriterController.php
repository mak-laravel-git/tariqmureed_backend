<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WriterResource;
use App\Models\Writer;
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
}
