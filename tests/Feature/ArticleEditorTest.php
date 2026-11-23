<?php

use App\Enums\RoleEnum;
use App\Models\Article;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function signInAsEditor(): void
{
    $user = User::factory()->create();
    $user->assignRole(RoleEnum::ADMIN->value);
    Sanctum::actingAs($user);
}

it('saves the slug, image details, SEO fields and flags of the editor', function () {
    signInAsEditor();

    $data = $this->postJson('/api/v1/admin/articles', [
        'title' => 'A Long Working Title',
        'slug' => 'short-url',
        'status' => 'Published',
        'image' => 'https://cdn.example.com/lead.jpg',
        'imageCaption' => 'The final over',
        'imageCredit' => 'Photo: TMN Sport',
        'socialImage' => 'https://cdn.example.com/share.jpg',
        'seoTitle' => 'SEO title',
        'seoDescription' => 'SEO description',
        'isFeatured' => '1',
        'isBreaking' => '0',
    ])->assertCreated()->json('data');

    expect($data)->toMatchArray([
        'slug' => 'short-url', 'imageCaption' => 'The final over', 'imageCredit' => 'Photo: TMN Sport', 'socialImage' => 'https://cdn.example.com/share.jpg',
        'seoTitle' => 'SEO title', 'seoDescription' => 'SEO description', 'isFeatured' => true, 'isBreaking' => false,
    ]);

    $this->getJson('/api/v1/articles/short-url')
        ->assertOk()
        ->assertJsonPath('data.caption', 'The final over')
        ->assertJsonPath('data.credit', 'Photo: TMN Sport')
        ->assertJsonPath('data.seoTitle', 'SEO title')
        ->assertJsonPath('data.socialImage', 'https://cdn.example.com/share.jpg');
});

it('keeps slugs unique and url friendly', function () {
    signInAsEditor();
    Article::factory()->create(['slug' => 'taken']);

    $this->postJson('/api/v1/admin/articles', ['title' => 'X', 'slug' => 'taken', 'status' => 'Draft'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->postJson('/api/v1/admin/articles', ['title' => 'X', 'slug' => 'Not A Slug!', 'status' => 'Draft'])->assertUnprocessable()->assertJsonValidationErrors('slug');

    $id = $this->postJson('/api/v1/admin/articles', ['title' => 'Own slug', 'slug' => 'own-slug', 'status' => 'Draft'])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/admin/articles/{$id}", ['title' => 'Own slug', 'slug' => 'own-slug', 'status' => 'Draft'])->assertOk();
});

it('puts the featured story first and builds the breaking news bar', function () {
    $this->getJson('/api/v1/ticker')->assertNotFound();

    Article::factory()->create(['title' => 'Newest story', 'slug' => 'newest']);
    $featured = Article::factory()->create(['title' => 'Featured story', 'slug' => 'featured', 'is_featured' => true, 'published_at' => now()->subDay()]);
    Article::factory()->create(['title' => 'Breaking story', 'slug' => 'breaking', 'is_breaking' => true, 'published_at' => now()->subHours(2)]);
    Article::factory()->draft()->create(['is_breaking' => true, 'slug' => 'hidden']);

    $this->getJson('/api/v1/articles/featured')->assertOk()->assertJsonPath('data.lead.slug', $featured->slug);
    $this->getJson('/api/v1/ticker')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0', ['id' => Article::query()->where('slug', 'breaking')->value('id'), 'text' => 'Breaking story', 'href' => '/news/breaking']);
});

it('turns toolbar formatting into blocks', function () {
    Article::factory()->create([
        'slug' => 'formatted',
        'body' => implode("\n\n", [
            'Opening with **bold** words.',
            '# Big heading',
            "- first\n- second",
            "1. one\n2. two",
            '> A quote',
            '![Stadium](https://cdn.example.com/stadium.jpg)',
            '[video](https://www.youtube.com/watch?v=abc123)',
            "| Team | Wins |\n| --- | --- |\n| Dhaka | 5 |",
            '![bad](javascript:alert(1))',
        ]),
    ]);

    $blocks = $this->getJson('/api/v1/articles/formatted')->assertOk()->json('data.blocks');

    expect(array_column($blocks, 'type'))->toBe(['lead', 'heading', 'list', 'list', 'quote', 'image', 'video', 'table', 'paragraph'])
        ->and($blocks[2])->toMatchArray(['ordered' => false, 'items' => ['first', 'second']])
        ->and($blocks[3])->toMatchArray(['ordered' => true, 'items' => ['one', 'two']])
        ->and($blocks[5])->toMatchArray(['src' => 'https://cdn.example.com/stadium.jpg', 'text' => 'Stadium'])
        ->and($blocks[7]['rows'])->toBe([['Team', 'Wins'], ['Dhaka', '5']]);
});

it('lists the tags already used', function () {
    signInAsEditor();
    Article::factory()->create(['tags' => 'IPL 2026, Transfer News']);
    Article::factory()->create(['tags' => 'IPL 2026']);

    $this->getJson('/api/v1/admin/articles/tags')->assertOk()->assertJsonPath('data.0', 'IPL 2026')->assertJsonCount(2, 'data');
});
