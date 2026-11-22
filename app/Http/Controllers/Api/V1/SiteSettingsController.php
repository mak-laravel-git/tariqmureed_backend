<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;

class SiteSettingsController extends Controller
{
    /**
     * The settings the public website needs (contact details, Google tag IDs, consent mode).
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => SiteSettings::public()]);
    }
}
