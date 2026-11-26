<?php

use App\Enums\RoleEnum;
use App\Models\Article;
use App\Models\SeoPage;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function signInAsSeoAdmin(RoleEnum $role = RoleEnum::ADMIN): void
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);
}

it('serves the global SEO settings and the text of every page to the website', function () {
    $data = $this->getJson('/api/v1/seo')->assertOk()->json('data');

    expect($data['settings'])->toHaveKeys(['seoSiteUrl', 'seoTitleTemplate', 'seoDefaultImage', 'seoTwitterHandle', 'seoIndexing', 'seoGoogleVerification', 'seoBingVerification'])
        ->and($data['settings']['seoIndexing'])->toBeTrue()
        ->and($data['settings']['seoTitleTemplate'])->toBe('%s | TMN Sport')
        ->and($data['pages'])->toHaveKeys(['/', '/news', '/live-scores', '/videos'])
        ->and($data['pages']['/news']['title'])->toBe('Latest News')
        ->and($data['pages']['/news']['noindex'])->toBeFalse();
});

it('lets an admin write search text for each page and falls back to the built-in text when it is cleared', function () {
    signInAsSeoAdmin();

    $this->putJson('/api/v1/admin/seo', ['pages' => [
        ['path' => '/news', 'title' => 'Cricket News Today', 'description' => 'All the cricket news.', 'image' => 'https://cdn.example.com/news.jpg', 'noindex' => false],
        ['path' => '/records', 'title' => '', 'description' => '', 'noindex' => true],
    ]])->assertOk()->assertJsonPath('data.pages.1.title', 'Cricket News Today');

    $pages = $this->getJson('/api/v1/seo')->json('data.pages');
    expect($pages['/news']['title'])->toBe('Cricket News Today')
        ->and($pages['/news']['image'])->toBe('https://cdn.example.com/news.jpg')
        ->and($pages['/records']['title'])->toBe('Cricket Records')
        ->and($pages['/records']['noindex'])->toBeTrue();

    $this->putJson('/api/v1/admin/seo', ['pages' => [['path' => '/news', 'title' => '', 'description' => '']]])->assertOk();

    expect($this->getJson('/api/v1/seo')->json('data.pages./news.title'))->toBe('Latest News')
        ->and(SeoPage::query()->where('path', '/news')->count())->toBe(1);
});

it('rejects pages that are not on the website and unreadable text', function () {
    signInAsSeoAdmin();

    $this->putJson('/api/v1/admin/seo', ['pages' => [['path' => '/admin', 'title' => 'x']]])->assertUnprocessable()->assertJsonValidationErrors('pages.0.path');
    $this->putJson('/api/v1/admin/seo', ['pages' => [['path' => '/news', 'title' => str_repeat('a', 121)]]])->assertUnprocessable()->assertJsonValidationErrors('pages.0.title');
});

it('keeps the SEO screen for people who can see website settings', function () {
    signInAsSeoAdmin(RoleEnum::USER);

    $this->getJson('/api/v1/admin/seo')->assertForbidden();
    $this->putJson('/api/v1/admin/seo', ['pages' => []])->assertForbidden();
});

it('saves the global SEO settings with the website settings and validates them', function () {
    signInAsSeoAdmin();

    $this->putJson('/api/v1/admin/settings', [
        'seoSiteUrl' => 'https://tmnsport.com',
        'seoTitleTemplate' => '%s — TMN Sport',
        'seoTwitterHandle' => '@tmnsport',
        'seoIndexing' => false,
        'seoGoogleVerification' => 'abcDEF1234567890-xyz',
    ])->assertOk()->assertJsonPath('data.seoSiteUrl', 'https://tmnsport.com')->assertJsonPath('data.seoIndexing', false);

    expect($this->getJson('/api/v1/seo')->json('data.settings.seoIndexing'))->toBeFalse();

    $this->putJson('/api/v1/admin/settings', ['seoTitleTemplate' => 'No placeholder'])->assertUnprocessable()->assertJsonValidationErrors('seoTitleTemplate');
    $this->putJson('/api/v1/admin/settings', ['seoSiteUrl' => 'tmnsport.com'])->assertUnprocessable()->assertJsonValidationErrors('seoSiteUrl');
    $this->putJson('/api/v1/admin/settings', ['seoTwitterHandle' => '@bad handle!'])->assertUnprocessable()->assertJsonValidationErrors('seoTwitterHandle');
    $this->putJson('/api/v1/admin/settings', ['seoGoogleVerification' => '<meta name="x" content="y">'])->assertUnprocessable()->assertJsonValidationErrors('seoGoogleVerification');
});

it('reports what to fix on the SEO screen', function () {
    signInAsSeoAdmin();
    Article::factory()->create(['status' => Article::PUBLISHED, 'published_at' => now()->subHour(), 'seo_description' => null, 'image' => null]);
    Article::factory()->create(['status' => Article::PUBLISHED, 'published_at' => now()->subHour(), 'seo_description' => 'Has one', 'image' => '/a.jpg', 'noindex' => true]);

    $health = collect($this->getJson('/api/v1/admin/seo')->assertOk()->json('data.health'))->keyBy('key');

    expect($health['indexing']['status'])->toBe('good')
        ->and($health['site-url']['status'])->toBe('warning')
        ->and($health['story-description']['status'])->toBe('warning')
        ->and($health['story-description']['detail'])->toContain('1 of 2')
        ->and($health['story-image']['status'])->toBe('warning')
        ->and($health['hidden']['status'])->toBe('info');
});

it('tells the website when a story was last edited so search engines get a true modified date', function () {
    $editedAt = now()->subHour()->startOfSecond();

    $this->travelTo($editedAt);
    Article::factory()->create(['status' => Article::PUBLISHED, 'published_at' => $editedAt->copy()->subDay(), 'slug' => 'edited-story']);
    $this->travelBack();

    $data = $this->getJson('/api/v1/articles/edited-story')->assertOk()->json('data');

    expect(Carbon\Carbon::parse($data['updatedAt'])->equalTo($editedAt))->toBeTrue()
        ->and($data['updatedAt'])->not->toBe($data['publishedAt']);
});

it('keeps a story out of the sitemap and search results when marked do not index', function () {
    signInAsSeoAdmin();

    $visible = Article::factory()->create(['status' => Article::PUBLISHED, 'published_at' => now()->subHour(), 'slug' => 'open-story']);
    Article::factory()->create(['status' => Article::PUBLISHED, 'published_at' => now()->subHour(), 'slug' => 'hidden-story', 'noindex' => true]);
    Article::factory()->create(['status' => Article::DRAFT, 'slug' => 'draft-story']);

    $slugs = collect($this->getJson('/api/v1/sitemap')->assertOk()->json('data.articles'))->pluck('slug');

    expect($slugs->all())->toBe(['open-story'])
        ->and($this->getJson('/api/v1/articles/hidden-story')->json('data.noindex'))->toBeTrue()
        ->and($this->getJson("/api/v1/articles/{$visible->slug}")->json('data.noindex'))->toBeFalse();

    $id = $this->postJson('/api/v1/admin/articles', ['title' => 'Private', 'status' => 'Published', 'noindex' => true])->assertCreated()->assertJsonPath('data.noindex', true)->json('data.id');
    expect(Article::query()->findOrFail($id)->noindex)->toBeTrue();
});
