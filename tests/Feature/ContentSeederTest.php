<?php

use App\Models\Article;
use App\Models\Category;
use App\Models\Writer;
use Database\Seeders\ContentSeeder;

it('fills the news pages with sample stories, authors and categories', function () {
    $this->seed(ContentSeeder::class);

    expect(Article::query()->visible()->count())->toBe(18)
        ->and(Writer::query()->count())->toBe(8)
        ->and(Category::query()->where('name', 'Domestic')->exists())->toBeTrue();

    $this->getJson('/api/v1/articles/featured')->assertOk()->assertJsonPath('data.lead.category', 'IPL 2026')->assertJsonCount(4, 'data.side');
    $this->getJson('/api/v1/articles/most-read')->assertOk()->assertJsonCount(5, 'data');
    $this->getJson('/api/v1/news?limit=6')->assertOk()->assertJsonCount(6, 'data.articles');
    $this->getJson('/api/v1/writers')->assertOk()->assertJsonCount(8, 'data');
    $this->getJson('/api/v1/articles/a-fearless-generation-is-rewriting-the-rules-of-the-modern-game')
        ->assertOk()
        ->assertJsonPath('data.blocks.0.type', 'lead');
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
