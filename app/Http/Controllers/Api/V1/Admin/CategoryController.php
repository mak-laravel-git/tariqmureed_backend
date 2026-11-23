<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Category;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @extends ResourceController<Category>
 */
class CategoryController extends ResourceController
{
    protected function model(): string
    {
        return Category::class;
    }

    protected function subject(): string
    {
        return 'category';
    }

    protected function searchColumns(): array
    {
        return ['name', 'description'];
    }

    protected function rules(Request $request): array
    {
        return [
            'title' => ['required', 'string', 'max:80', Rule::unique('categories', 'name')->ignore($request->route('id'))],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function save(Model $model, array $data): void
    {
        $model->fill([
            'name' => $data['title'],
            'description' => $data['subtitle'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'image' => $data['image'] ?? null,
        ]);

        if (! $model->exists) {
            $model->slug = Category::uniqueSlug($data['title']);
        }

        $model->save();
    }

    protected function record(Model $model): array
    {
        return [
            'id' => (string) $model->id,
            'title' => $model->name,
            'subtitle' => (string) $model->description,
            'owner' => $model->articles()->count().' articles',
            'status' => $model->status,
            'updatedAt' => $model->updated_at->diffForHumans(),
            'notes' => $model->notes,
            'image' => $model->image,
            'thumbnail' => $model->image ?: $model->articles()->whereNotNull('image')->where('image', '!=', '')->latest('publish_at')->value('image'),
        ];
    }
}
