<?php

use App\Enums\RoleEnum;
use App\Models\ActivityLog;
use App\Models\Advertisement;
use App\Models\Article;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Writer;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function signInForContent(RoleEnum $role = RoleEnum::ADMIN): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);

    return $user;
}

it('keeps every content module behind its permission', function () {
    signInForContent(RoleEnum::USER);

    foreach (['articles', 'categories', 'writers', 'advertisements', 'media', 'activity-logs'] as $module) {
        $this->getJson("/api/v1/admin/{$module}")->assertForbidden();
    }

    $this->postJson('/api/v1/admin/articles', ['title' => 'X', 'status' => 'Draft'])->assertForbidden();
});

it('writes an article, creates its category and credits the author', function () {
    $writer = Writer::factory()->create(['name' => 'Nadia Rahman']);
    signInForContent();

    $response = $this->postJson('/api/v1/admin/articles', [
        'title' => 'A Big Win In Dhaka',
        'subtitle' => 'International',
        'owner' => 'Nadia Rahman',
        'status' => 'Published',
        'summary' => 'Short summary.',
        'body' => "First paragraph.\n\n## A heading\n\n> A quote\n\nLast paragraph.",
        'tags' => 'bpl, dhaka',
    ])->assertCreated();

    $article = Article::query()->firstOrFail();

    expect($article)
        ->slug->toBe('a-big-win-in-dhaka')
        ->writer_id->toBe($writer->id)
        ->published_at->not->toBeNull()
        ->and($response->json('data'))->toMatchArray(['subtitle' => 'International', 'owner' => 'Nadia Rahman', 'status' => 'Published'])
        ->and(Category::query()->where('name', 'International')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('action', 'created')->where('subject', 'article')->exists())->toBeTrue();
});

it('shows published articles on the public site and keeps drafts private', function () {
    $this->getJson('/api/v1/articles/featured')->assertNotFound();
    $this->getJson('/api/v1/news')->assertNotFound();

    $category = Category::factory()->create(['name' => 'International', 'slug' => 'international']);
    $writer = Writer::factory()->create();
    $published = Article::factory()->for($category)->for($writer)->create([
        'title' => 'Published Story',
        'slug' => 'published-story',
        'body' => "Lead paragraph here.\n\n## Middle heading\n\n> Wise words\n\nClosing paragraph.",
        'tags' => 'one, two',
    ]);
    Article::factory()->draft()->create(['title' => 'Secret Draft', 'slug' => 'secret-draft']);
    Article::factory()->create(['slug' => 'later', 'status' => Article::SCHEDULED, 'published_at' => now()->addDay()]);

    $this->getJson('/api/v1/articles/featured')->assertOk()->assertJsonPath('data.lead.slug', 'published-story');
    $this->getJson('/api/v1/articles?per_page=5')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/articles/top-headlines')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/articles/most-read')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/articles/secret-draft')->assertNotFound();
    $this->getJson('/api/v1/articles/later')->assertNotFound();

    $detail = $this->getJson('/api/v1/articles/published-story')->assertOk()->json('data');

    expect(array_column($detail['blocks'], 'type'))->toBe(['lead', 'heading', 'quote', 'paragraph'])
        ->and($detail['tags'])->toBe(['one', 'two'])
        ->and($detail['breadcrumb'])->toBe(['International'])
        ->and($detail['category'])->toBe('International')
        ->and($published->refresh()->views)->toBeGreaterThan(0);

    $news = $this->getJson('/api/v1/news?limit=5')->assertOk()->json('data');

    expect($news['featured']['slug'])->toBe('published-story')
        ->and($news['categories'])->toBe([['slug' => 'international', 'name' => 'International']])
        ->and($news['total'])->toBe(0);
});

it('filters the news page by category', function () {
    $cricket = Category::factory()->create(['name' => 'Cricket', 'slug' => 'cricket']);
    $other = Category::factory()->create(['name' => 'Other', 'slug' => 'other']);
    Article::factory()->for($cricket)->count(2)->create();
    Article::factory()->for($other)->create();

    $this->getJson('/api/v1/news?category=cricket')->assertOk()->assertJsonCount(1, 'data.articles')->assertJsonPath('data.total', 1);
});

it('lists authors who have published stories', function () {
    $this->getJson('/api/v1/writers')->assertNotFound();

    $writer = Writer::factory()->create(['slug' => 'nadia-rahman']);
    Article::factory()->for($writer)->create();

    $this->getJson('/api/v1/writers')->assertOk()->assertJsonPath('data.0.articleCount', 1);
    $this->getJson('/api/v1/writers/nadia-rahman')->assertOk()->assertJsonPath('data.slug', 'nadia-rahman');
});

it('searches, filters and edits articles from the admin table', function () {
    Article::factory()->create(['title' => 'Alpha cup final']);
    $draft = Article::factory()->draft()->create(['title' => 'Beta preview']);
    signInForContent();

    $this->getJson('/api/v1/admin/articles?q=alpha')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/admin/articles?tab=pending')->assertOk()->assertJsonPath('data.0.title', 'Beta preview');

    $this->putJson("/api/v1/admin/articles/{$draft->id}", ['title' => 'Beta preview', 'status' => 'Published'])->assertOk();
    expect($draft->refresh())->status->toBe('Published')->published_at->not->toBeNull();

    $this->putJson("/api/v1/admin/articles/{$draft->id}", ['title' => 'Beta preview', 'status' => 'Archived'])->assertOk();
    expect($draft->refresh()->published_at)->toBeNull();

    $this->deleteJson("/api/v1/admin/articles/{$draft->id}")->assertNoContent();
    $this->assertModelMissing($draft);
});

it('reads the publish date in the site timezone, so a story dated now goes live now', function () {
    config(['sportmonks.display_timezone' => 'Asia/Dhaka', 'app.timezone' => 'UTC']);
    $this->travelTo(now()->setTime(5, 40));
    signInForContent();

    // 11:30 in Dhaka is 05:30 UTC, ten minutes ago.
    $id = $this->postJson('/api/v1/admin/articles', ['title' => 'Typed in Dhaka time', 'status' => 'Published', 'publishAt' => now()->setTime(11, 30)->format('Y-m-d\TH:i')])
        ->assertCreated()
        ->assertJsonPath('data.publishAt', now()->setTime(11, 30)->format('Y-m-d\TH:i'))
        ->json('data.id');

    expect(Article::query()->visible()->whereKey($id)->exists())->toBeTrue();
});

it('hides a story dated in the future until that time, and publishes it when the date is cleared', function () {
    config(['sportmonks.display_timezone' => 'Asia/Dhaka']);
    $this->travelTo(now()->setTime(5, 40));
    signInForContent();

    $id = $this->postJson('/api/v1/admin/articles', ['title' => 'Later today', 'status' => 'Published', 'publishAt' => now()->setTime(16, 30)->format('Y-m-d\TH:i')])
        ->assertCreated()->json('data.id');
    expect(Article::query()->visible()->whereKey($id)->exists())->toBeFalse();

    $this->putJson("/api/v1/admin/articles/{$id}", ['title' => 'Later today', 'status' => 'Published', 'publishAt' => ''])->assertOk();
    expect(Article::query()->visible()->whereKey($id)->exists())->toBeTrue();
});

it('rejects an article with an unknown status', function () {
    signInForContent();

    $this->postJson('/api/v1/admin/articles', ['title' => 'X', 'status' => 'Live'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('manages categories, authors and advertisements', function () {
    signInForContent();

    $category = $this->postJson('/api/v1/admin/categories', ['title' => 'Football', 'subtitle' => 'Beautiful game', 'status' => 'Active'])
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/admin/categories', ['title' => 'Football', 'status' => 'Active'])->assertUnprocessable();
    $this->putJson("/api/v1/admin/categories/{$category}", ['title' => 'Soccer', 'status' => 'Archived'])->assertOk()->assertJsonPath('data.title', 'Soccer');

    $this->postJson('/api/v1/admin/writers', ['title' => 'Priya Nair', 'subtitle' => 'Analyst', 'owner' => 'Women\'s cricket', 'status' => 'Active', 'notes' => 'Bio text'])
        ->assertCreated()->assertJsonPath('data.notes', 'Bio text');
    expect(Writer::query()->first()->slug)->toBe('priya-nair');

    $this->postJson('/api/v1/admin/advertisements', ['title' => 'Summer campaign', 'subtitle' => 'Homepage', 'owner' => 'Acme', 'status' => 'Active'])->assertCreated();
    expect(Advertisement::query()->count())->toBe(1);

    $this->deleteJson("/api/v1/admin/categories/{$category}")->assertNoContent();
});

it('uploads and deletes an image', function () {
    Storage::fake('public');
    signInForContent();

    $id = $this->postJson('/api/v1/admin/media', ['file' => UploadedFile::fake()->image('stadium night.jpg', 800, 600)])
        ->assertCreated()
        ->assertJsonPath('data.title', 'stadium night')
        ->json('data.id');

    $asset = MediaAsset::query()->findOrFail($id);
    Storage::disk('public')->assertExists($asset->path);
    expect($asset->url())->toEndWith('/storage/'.$asset->path);
    $this->getJson('/api/v1/admin/media')->assertOk()->assertJsonCount(1, 'data');

    $this->deleteJson("/api/v1/admin/media/{$id}")->assertNoContent();
    Storage::disk('public')->assertMissing($asset->path);
});

it('names an uploaded image with the title the editor typed', function () {
    Storage::fake('public');
    signInForContent();

    $this->postJson('/api/v1/admin/media', ['file' => UploadedFile::fake()->image('IMG_0001.png'), 'title' => 'Dhaka stadium at night'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Dhaka stadium at night');
});

it('rejects files that are not images', function () {
    Storage::fake('public');
    signInForContent();

    $this->postJson('/api/v1/admin/media', ['file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')])->assertUnprocessable();
    $this->postJson('/api/v1/admin/media', ['file' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertUnprocessable();
});

it('records dashboard actions in the activity log', function () {
    $admin = signInForContent();
    ActivityLog::record($admin, 'updated', 'settings', 'Website settings');

    $this->getJson('/api/v1/admin/activity-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.owner', $admin->name)
        ->assertJsonPath('data.0.subtitle', 'updated settings');
});
