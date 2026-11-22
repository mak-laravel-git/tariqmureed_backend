<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdatePageRequest;
use App\Models\SitePage;
use Illuminate\Http\JsonResponse;

class PageController extends Controller
{
    /**
     * The editable pages and whether each one has been customised.
     */
    public function index(): JsonResponse
    {
        $stored = SitePage::query()->get()->keyBy('slug');

        $pages = collect(SitePage::SLUGS)->map(fn (string $label, string $slug): array => [
            'slug' => $slug,
            'label' => $label,
            'customised' => $stored->has($slug),
            'updatedAt' => $stored->get($slug)?->updated_at?->diffForHumans(),
        ])->values();

        return response()->json(['data' => $pages]);
    }

    /**
     * The saved text of a page, or 404 while the website's built-in text is still in use.
     */
    public function show(string $slug): JsonResponse
    {
        return response()->json(['data' => $this->payload(SitePage::query()->where('slug', $this->knownSlug($slug))->firstOrFail())]);
    }

    public function update(UpdatePageRequest $request, string $slug): JsonResponse
    {
        $page = SitePage::query()->updateOrCreate(
            ['slug' => $this->knownSlug($slug)],
            ['title' => $request->validated('title'), 'description' => $request->validated('description'), 'content' => $request->validated('content')],
        );

        return response()->json(['data' => $this->payload($page)]);
    }

    /**
     * Remove the saved text so the website's built-in text is used again.
     */
    public function destroy(string $slug): JsonResponse
    {
        SitePage::query()->where('slug', $this->knownSlug($slug))->delete();

        return response()->json(['message' => 'The page was reset to its built-in text.']);
    }

    private function knownSlug(string $slug): string
    {
        abort_unless(array_key_exists($slug, SitePage::SLUGS), 404);

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SitePage $page): array
    {
        return [
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => (string) $page->description,
            'content' => $page->content,
            'updatedAt' => $page->updated_at?->diffForHumans(),
        ];
    }
}
