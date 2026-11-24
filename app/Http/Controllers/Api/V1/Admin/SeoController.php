<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Article;
use App\Models\SeoPage;
use App\Support\SeoPages;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SeoController extends Controller
{
    /**
     * The admin SEO screen: each page with its current text, and a short health check of the whole site.
     */
    public function show(): JsonResponse
    {
        $saved = SeoPage::query()->get()->keyBy('path');

        $pages = collect(SeoPages::catalog())->map(fn (array $page, string $path): array => [
            'path' => $path,
            'label' => $page['label'],
            'defaultTitle' => $page['title'],
            'defaultDescription' => $page['description'],
            'title' => (string) ($saved->get($path)?->title ?? ''),
            'description' => (string) ($saved->get($path)?->description ?? ''),
            'image' => (string) ($saved->get($path)?->image ?? ''),
            'noindex' => (bool) ($saved->get($path)?->noindex ?? false),
        ])->values();

        return response()->json(['data' => [
            'pages' => $pages,
            'health' => $this->health(),
        ]]);
    }

    /**
     * Save the text of every page in one go. A blank title or description goes back to the page's own text.
     */
    public function update(Request $request): JsonResponse
    {
        $paths = array_keys(SeoPages::catalog());

        $data = $request->validate([
            'pages' => ['required', 'array'],
            'pages.*.path' => ['required', 'string', Rule::in($paths)],
            'pages.*.title' => ['nullable', 'string', 'max:120'],
            'pages.*.description' => ['nullable', 'string', 'max:300'],
            'pages.*.image' => ['nullable', 'string', 'max:500'],
            'pages.*.noindex' => ['nullable', 'boolean'],
        ]);

        foreach ($data['pages'] as $page) {
            SeoPage::query()->updateOrCreate(['path' => $page['path']], [
                'title' => filled($page['title'] ?? null) ? trim($page['title']) : null,
                'description' => filled($page['description'] ?? null) ? trim($page['description']) : null,
                'image' => filled($page['image'] ?? null) ? trim($page['image']) : null,
                'noindex' => (bool) ($page['noindex'] ?? false),
            ]);
        }

        ActivityLog::record($request->user(), 'updated', 'seo', 'Page SEO');

        return $this->show();
    }

    /**
     * Quick checks an editor can act on: each one says what is wrong and where to fix it.
     *
     * @return array<int, array{key: string, label: string, status: string, detail: string}>
     */
    private function health(): array
    {
        $settings = SiteSettings::all();
        $published = Article::query()->visible();
        $total = (clone $published)->count();
        $noDescription = (clone $published)->where(fn ($query) => $query->whereNull('seo_description')->orWhere('seo_description', ''))->count();
        $noImage = (clone $published)->where(fn ($query) => $query->whereNull('image')->orWhere('image', ''))->count();
        $hidden = (clone $published)->where('noindex', true)->count();

        $check = fn (string $key, string $label, bool $ok, string $detail, string $warn = 'warning'): array => [
            'key' => $key,
            'label' => $label,
            'status' => $ok ? 'good' : $warn,
            'detail' => $detail,
        ];

        return [
            $check('indexing', 'Search engines are allowed', (bool) $settings['seoIndexing'], $settings['seoIndexing'] ? 'The site can appear in Google and Bing.' : 'Indexing is switched off: search engines are told to stay away.', 'error'),
            $check('site-url', 'Website address is set', filled($settings['seoSiteUrl']), filled($settings['seoSiteUrl']) ? (string) $settings['seoSiteUrl'] : 'Set the live address (https://tmnsport.com) so sitemap.xml and share links use it.'),
            $check('image', 'Default share picture is set', filled($settings['seoDefaultImage']), filled($settings['seoDefaultImage']) ? 'Shown when a page is shared and has no picture of its own.' : 'Choose a picture for links shared on social networks.'),
            $check('google', 'Google Search Console is verified', filled($settings['seoGoogleVerification']), filled($settings['seoGoogleVerification']) ? 'Verification tag is on every page.' : 'Add the verification code from Google Search Console.'),
            $check('story-description', 'Stories have a search description', $noDescription === 0, $noDescription === 0 ? "All {$total} published stories have one." : "{$noDescription} of {$total} published stories have none; Google then picks its own text."),
            $check('story-image', 'Stories have a picture', $noImage === 0, $noImage === 0 ? 'Every published story has a lead picture.' : "{$noImage} published stories have no picture, so they share poorly."),
            $check('hidden', 'Stories hidden from search', $hidden === 0, $hidden === 0 ? 'No story is hidden.' : "{$hidden} published stories are marked 'do not index'.", 'info'),
        ];
    }
}
