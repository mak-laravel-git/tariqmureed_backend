<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Support\AdPlacements;
use Illuminate\Http\JsonResponse;

class AdController extends Controller
{
    /**
     * The ad to show on every placement, keyed by placement. A placement with nothing running is left out.
     * When several ads share a placement the newest one wins.
     */
    public function __invoke(): JsonResponse
    {
        $ads = Advertisement::query()
            ->running()
            ->whereIn('placement', AdPlacements::keys())
            ->latest('id')
            ->get()
            ->unique('placement')
            ->filter(fn (Advertisement $ad): bool => $ad->type === Advertisement::TYPE_ADSENSE ? filled($ad->adsense_slot) : filled($ad->image))
            ->mapWithKeys(fn (Advertisement $ad): array => [$ad->placement => [
                'id' => $ad->id,
                'type' => $ad->type,
                'name' => $ad->name,
                'image' => $ad->image,
                'href' => $ad->link_url,
                'slot' => $ad->adsense_slot,
            ]]);

        return response()->json(['data' => (object) $ads->all()]);
    }
}
