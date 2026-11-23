<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Writer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * @extends ResourceController<Writer>
 */
class WriterController extends ResourceController
{
    protected function model(): string
    {
        return Writer::class;
    }

    protected function subject(): string
    {
        return 'author';
    }

    protected function searchColumns(): array
    {
        return ['name', 'title', 'desk'];
    }

    protected function rules(Request $request): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:120'],
            'owner' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function save(Model $model, array $data): void
    {
        $model->fill([
            'name' => $data['title'],
            'title' => $data['subtitle'] ?? null,
            'desk' => $data['owner'] ?? null,
            'status' => $data['status'],
            'bio' => $data['notes'] ?? null,
        ]);

        if (! $model->exists) {
            $model->slug = Writer::uniqueSlug($data['title']);
        }

        $model->save();
    }

    protected function record(Model $model): array
    {
        return [
            'id' => (string) $model->id,
            'title' => $model->name,
            'subtitle' => (string) $model->title,
            'owner' => (string) $model->desk,
            'status' => $model->status,
            'updatedAt' => $model->updated_at->diffForHumans(),
            'notes' => $model->bio,
        ];
    }
}
