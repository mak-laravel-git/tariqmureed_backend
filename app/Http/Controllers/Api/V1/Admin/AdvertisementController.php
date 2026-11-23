<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Advertisement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * @extends ResourceController<Advertisement>
 */
class AdvertisementController extends ResourceController
{
    protected function model(): string
    {
        return Advertisement::class;
    }

    protected function subject(): string
    {
        return 'advertisement';
    }

    protected function searchColumns(): array
    {
        return ['name', 'placement', 'advertiser'];
    }

    protected function rules(Request $request): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['nullable', 'string', 'max:120'],
            'owner' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function save(Model $model, array $data): void
    {
        $model->fill([
            'name' => $data['title'],
            'placement' => $data['subtitle'] ?? null,
            'advertiser' => $data['owner'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ])->save();
    }

    protected function record(Model $model): array
    {
        return [
            'id' => (string) $model->id,
            'title' => $model->name,
            'subtitle' => (string) $model->placement,
            'owner' => (string) $model->advertiser,
            'status' => $model->status,
            'updatedAt' => $model->updated_at->diffForHumans(),
            'notes' => $model->notes,
        ];
    }
}
