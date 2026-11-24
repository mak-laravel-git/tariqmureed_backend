<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Article;
use App\Models\Category;
use App\Models\Writer;
use App\Support\EditorialTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @extends ResourceController<Article>
 */
class ArticleController extends ResourceController
{
    protected function model(): string
    {
        return Article::class;
    }

    protected function subject(): string
    {
        return 'article';
    }

    protected function searchColumns(): array
    {
        return ['title', 'summary', 'tags'];
    }

    protected function relations(): array
    {
        return ['category', 'writer'];
    }

    protected function rules(Request $request): array
    {
        return [
            'title' => ['required', 'string', 'max:190'],
            'subtitle' => ['nullable', 'string', 'max:80'],
            'owner' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in([Article::DRAFT, Article::PENDING, Article::SCHEDULED, Article::PUBLISHED, Article::ARCHIVED])],
            'summary' => ['nullable', 'string', 'max:600'],
            'body' => ['nullable', 'string', 'max:100000'],
            'tags' => ['nullable', 'string', 'max:190'],
            'publishAt' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'string', 'max:500'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('articles', 'slug')->ignore($request->route('id'))],
            'imageCaption' => ['nullable', 'string', 'max:250'],
            'imageCredit' => ['nullable', 'string', 'max:150'],
            'socialImage' => ['nullable', 'string', 'max:500'],
            'gallery' => ['nullable', 'array', 'max:30'],
            'gallery.*' => ['nullable', 'string', 'max:500'],
            'seoTitle' => ['nullable', 'string', 'max:120'],
            'seoDescription' => ['nullable', 'string', 'max:300'],
            'isFeatured' => ['nullable', 'boolean'],
            'isBreaking' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['slug.regex' => 'The URL slug can only hold lowercase letters, numbers and hyphens.', 'slug.unique' => 'Another article already uses this URL slug.'];
    }

    /**
     * Every tag already used on an article, for the tag chips of the editor.
     */
    public function tags(): JsonResponse
    {
        $tags = Article::query()->whereNotNull('tags')->pluck('tags')
            ->flatMap(fn (string $line): array => array_map('trim', explode(',', $line)))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(30)
            ->values();

        return response()->json(['data' => $tags]);
    }

    protected function save(Model $model, array $data): void
    {
        $categoryName = trim((string) ($data['subtitle'] ?? ''));
        $category = $categoryName === '' ? null : Category::query()->firstOrCreate(
            ['name' => $categoryName],
            ['slug' => Category::uniqueSlug($categoryName), 'status' => 'Active'],
        );

        $model->fill([
            'title' => $data['title'],
            'category_id' => $category?->id,
            'writer_id' => Writer::query()->where('name', trim((string) ($data['owner'] ?? '')))->value('id'),
            'status' => $data['status'],
            'summary' => $data['summary'] ?? null,
            'body' => $data['body'] ?? null,
            'tags' => $data['tags'] ?? null,
            'publish_at' => EditorialTime::toStored($data['publishAt'] ?? null),
            'notes' => $data['notes'] ?? null,
            'image' => ($data['image'] ?? '') !== '' ? $data['image'] : null,
            'image_caption' => $data['imageCaption'] ?? null,
            'image_credit' => $data['imageCredit'] ?? null,
            'social_image' => $data['socialImage'] ?? null,
            'gallery' => collect($data['gallery'] ?? [])->map(fn (?string $url): string => trim((string) $url))->filter()->unique()->values()->all() ?: null,
            'seo_title' => $data['seoTitle'] ?? null,
            'seo_description' => $data['seoDescription'] ?? null,
            'is_featured' => (bool) ($data['isFeatured'] ?? false),
            'is_breaking' => (bool) ($data['isBreaking'] ?? false),
        ]);

        if (filled($data['slug'] ?? null)) {
            $model->slug = $data['slug'];
        } elseif (! $model->exists) {
            $model->slug = Article::uniqueSlug($data['title']);
        }

        $model->syncPublishedAt();
        $model->save();
    }

    protected function record(Model $model): array
    {
        return [
            'id' => (string) $model->id,
            'title' => $model->title,
            'subtitle' => (string) $model->category?->name,
            'owner' => (string) $model->writer?->name,
            'status' => $model->status,
            'updatedAt' => $model->updated_at->diffForHumans(),
            'notes' => $model->notes,
            'thumbnail' => $model->image ?: null,
            'summary' => $model->summary,
            'body' => $model->body,
            'tags' => $model->tags,
            'publishAt' => EditorialTime::toInput($model->publish_at),
            'image' => $model->image,
            'slug' => $model->slug,
            'imageCaption' => $model->image_caption,
            'imageCredit' => $model->image_credit,
            'socialImage' => $model->social_image,
            'gallery' => $model->gallery ?? [],
            'seoTitle' => $model->seo_title,
            'seoDescription' => $model->seo_description,
            'isFeatured' => $model->is_featured,
            'isBreaking' => $model->is_breaking,
        ];
    }
}
