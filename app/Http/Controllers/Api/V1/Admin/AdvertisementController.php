<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Models\Advertisement;
use App\Support\AdPlacements;
use App\Support\EditorialTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * Where on the website an ad can be shown (the placement drop-down of the form).
     */
    public function placements(): JsonResponse
    {
        return response()->json(['data' => AdPlacements::options()]);
    }

    protected function rules(Request $request): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'subtitle' => ['required', Rule::in(AdPlacements::keys())],
            'owner' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(['Active', 'Draft', 'Archived'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'adType' => ['required', Rule::in([Advertisement::TYPE_IMAGE, Advertisement::TYPE_ADSENSE])],
            'image' => ['nullable', 'required_if:adType,'.Advertisement::TYPE_IMAGE, 'string', 'max:500'],
            'linkUrl' => ['nullable', 'url:http,https', 'max:500'],
            'adsenseSlot' => ['nullable', 'required_if:adType,'.Advertisement::TYPE_ADSENSE, 'regex:/^\d{6,20}$/'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'subtitle.in' => 'Choose where on the website the ad should appear.',
            'image.required_if' => 'Choose the banner image from the media library.',
            'adsenseSlot.required_if' => 'Enter the AdSense ad unit (slot) ID.',
            'adsenseSlot.regex' => 'The AdSense slot ID is a number of 6 to 20 digits.',
            'endsAt.after' => 'The end date must be after the start date.',
        ];
    }

    protected function save(Model $model, array $data): void
    {
        $isImage = $data['adType'] === Advertisement::TYPE_IMAGE;

        $model->fill([
            'name' => $data['title'],
            'placement' => $data['subtitle'],
            'advertiser' => $data['owner'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
            'type' => $data['adType'],
            'image' => $isImage ? ($data['image'] ?? null) : null,
            'link_url' => $isImage && filled($data['linkUrl'] ?? null) ? $data['linkUrl'] : null,
            'adsense_slot' => $isImage ? null : ($data['adsenseSlot'] ?? null),
            'starts_at' => EditorialTime::toStored($data['startsAt'] ?? null),
            'ends_at' => EditorialTime::toStored($data['endsAt'] ?? null),
        ])->save();
    }

    protected function record(Model $model): array
    {
        return [
            'id' => (string) $model->id,
            'title' => $model->name,
            'subtitle' => AdPlacements::label($model->placement),
            'owner' => (string) $model->advertiser,
            'status' => $model->status,
            'updatedAt' => $model->updated_at->diffForHumans(),
            'notes' => $model->notes,
            'placement' => $model->placement,
            'adType' => $model->type,
            'image' => $model->image,
            'linkUrl' => $model->link_url,
            'adsenseSlot' => $model->adsense_slot,
            'startsAt' => EditorialTime::toInput($model->starts_at),
            'endsAt' => EditorialTime::toInput($model->ends_at),
        ];
    }
}
