<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SitePage;
use App\Support\LegalPageContent;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;

class PageController extends Controller
{
    /** Pages that show the "change my cookie choices" button. */
    private const WITH_PRIVACY_SETTINGS = ['privacy-policy', 'cookie-preferences'];

    /**
     * A legal page edited in the admin dashboard. 404 means "use the website's built-in text".
     */
    public function show(string $slug): JsonResponse
    {
        $page = SitePage::query()->where('slug', $slug)->firstOrFail();
        $settings = SiteSettings::public();

        return response()->json(['data' => [
            'slug' => $page->slug,
            'title' => $page->title,
            'description' => (string) $page->description,
            'sections' => LegalPageContent::parse($page->content),
            'contact' => ['email' => $settings['contactEmail'], 'address' => $settings['contactAddress']],
            'privacySettings' => in_array($page->slug, self::WITH_PRIVACY_SETTINGS, true),
        ]]);
    }
}
