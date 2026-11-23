<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Models\ActivityLog;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    /**
     * Every website setting, as shown on the admin "Website Settings" page.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => SiteSettings::all()]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        // Blank inputs arrive as null: store them as empty strings ("not configured").
        $values = array_map(fn (mixed $value): mixed => $value ?? '', $request->validated());

        SiteSettings::update($values);
        ActivityLog::record($request->user(), 'updated', 'settings', 'Website settings');

        return response()->json(['data' => SiteSettings::all()]);
    }
}
