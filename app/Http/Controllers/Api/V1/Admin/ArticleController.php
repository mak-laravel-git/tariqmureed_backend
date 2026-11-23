<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Article;
use App\Models\Category;
use App\Models\Writer;
use App\Support\EditorialTime;
use Illuminate\Database\Eloquent\Model;
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
        ];
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
        ]);

        if (! $model->exists) {
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
            'summary' => $model->summary,
            'body' => $model->body,
            'tags' => $model->tags,
            'publishAt' => EditorialTime::toInput($model->publish_at),
            'image' => $model->image,
        ];
    }
}
