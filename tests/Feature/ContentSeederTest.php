<?php

use App\Models\Article;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Writer;
use Database\Seeders\ContentSeeder;

it('fills the dashboard with sample stories, authors and categories', function () {
    $this->seed(ContentSeeder::class);

    expect(Article::query()->where('status', Article::PUBLISHED)->where('notes', Article::SAMPLE_NOTE)->count())->toBe(18)
        ->and(Writer::query()->count())->toBe(8)
        ->and(Category::query()->where('name', 'Domestic')->exists())->toBeTrue()
        ->and(MediaAsset::query()->count())->toBeGreaterThan(0)
        ->and(Article::query()->where('image', 'like', '%/storage/media/seeded/%')->count())->toBe(18)
        ->and(Writer::query()->whereNotNull('avatar')->where('avatar', 'like', '%/storage/media/seeded/%')->count())->toBe(6)
        ->and(Category::query()->whereNotNull('image')->where('image', 'like', '%/storage/media/seeded/%')->count())->toBeGreaterThan(0);
});

it('keeps the made-up sample stories off the public site', function () {
    $this->seed(ContentSeeder::class);

    expect(Article::query()->visible()->count())->toBe(0);
    $this->getJson('/api/v1/articles/tamims-58-sets-169-for-rangpur-riders')->assertNotFound();
    $this->getJson('/api/v1/ticker')->assertNotFound();

    $real = Article::factory()->create(['title' => 'A real report', 'slug' => 'a-real-report', 'status' => Article::PUBLISHED, 'published_at' => now()->subHour()]);

    $this->getJson('/api/v1/articles')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'a-real-report');
    expect($this->getJson('/api/v1/ticker')->assertOk()->json('data.*.text'))->toBe([$real->title])
        ->and($this->getJson('/api/v1/news')->json('data.categories'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/writers')->json('data'))->toHaveCount(1);
});

it('shows a sample story once an editor has rewritten it', function () {
    $this->seed(ContentSeeder::class);
    $story = Article::query()->where('slug', 'tamims-58-sets-169-for-rangpur-riders')->firstOrFail();

    $story->update(['views' => 5]);
    expect(Article::query()->visible()->count())->toBe(0);

    $story->update(['title' => 'What really happened in Dhaka', 'body' => 'The report.']);

    expect(Article::query()->visible()->pluck('slug')->all())->toBe(['tamims-58-sets-169-for-rangpur-riders'])
        ->and($story->fresh()->notes)->toBeNull();
});

it('can run again without duplicating or overwriting what editors changed', function () {
    $this->seed(ContentSeeder::class);

    Article::query()->where('slug', 'tamims-58-sets-169-for-rangpur-riders')->update(['title' => 'Edited by an editor']);
    Writer::query()->where('slug', 'priya-nair')->delete();

    $this->seed(ContentSeeder::class);

    expect(Article::query()->count())->toBe(18)
        ->and(Article::query()->where('slug', 'tamims-58-sets-169-for-rangpur-riders')->value('title'))->toBe('Edited by an editor')
        ->and(Writer::query()->where('slug', 'priya-nair')->exists())->toBeTrue();
});
