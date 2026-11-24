<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Category;
use App\Models\Writer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The stories published on the old WordPress site (tmnsport.com), converted to the editor's plain-text format.
 *
 * The data lives in data/wordpress_articles.json. Pictures are imported into the media library first
 * (MediaLibrarySeeder), then attached to each story and its category. Safe to run again: a story that
 * already exists (matched by its slug) is left exactly as the editors changed it.
 */
class WordPressArticleSeeder extends Seeder
{
    private const NOTE = 'Imported from the old WordPress site by WordPressArticleSeeder.';

    public function run(): void
    {
        $this->call(MediaLibrarySeeder::class);

        /** @var array<int, array{title: string, slug: string, author: string, category: string, tags: string, publishedAt: string, summary: string, body: string, image: ?string, seoTitle: ?string, seoDescription: ?string, featured: bool}> $stories */
        $stories = json_decode((string) file_get_contents(__DIR__ . '/data/wordpress_articles.json'), true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, string> $categoryImages */
        $categoryImages = [];

        foreach ($stories as $story) {
            if (! empty($story['image'])) {
                $categoryImages[$story['category']] ??= MediaLibrarySeeder::url($story['image']);
            }

            $categoryImage = $categoryImages[$story['category']] ?? null;
            $category = Category::query()->firstOrCreate(
                ['name' => $story['category']],
                [
                    'slug' => Category::uniqueSlug($story['category']),
                    'status' => 'Active',
                    'image' => $categoryImage,
                ],
            );

            if ($categoryImage && ($category->image === null || str_starts_with((string) $category->image, '/images/'))) {
                $category->update(['image' => $categoryImage]);
            }

            $writer = Writer::query()->firstOrCreate(
                ['slug' => Str::slug($story['author'])],
                [
                    'name' => $story['author'],
                    'title' => 'Editor',
                    'desk' => 'Cricket desk',
                    'bio' => "{$story['author']} writes for TMN Sport.",
                    'status' => 'Active',
                ],
            );

            $mediaUrl = $story['image'] ? MediaLibrarySeeder::url($story['image']) : null;
            $body = MediaLibrarySeeder::rewriteBody($story['body']);
            $model = Article::query()->firstOrCreate(
                ['slug' => $story['slug']],
                [
                    'title' => $story['title'],
                    'summary' => $story['summary'] !== '' ? $story['summary'] : null,
                    'body' => $body,
                    'category_id' => $category->id,
                    'writer_id' => $writer->id,
                    'image' => $mediaUrl,
                    'tags' => $story['tags'] !== '' ? $story['tags'] : null,
                    'seo_title' => $story['seoTitle'],
                    'seo_description' => $story['seoDescription'],
                    'is_featured' => $story['featured'],
                    'status' => Article::PUBLISHED,
                    'publish_at' => $story['publishedAt'],
                    'published_at' => $story['publishedAt'],
                    'notes' => self::NOTE,
                ],
            );

            $updates = [];
            if ($mediaUrl && ($model->image === null || str_starts_with((string) $model->image, '/images/'))) {
                $updates['image'] = $mediaUrl;
            }
            if (str_contains((string) $model->body, '/images/')) {
                $updates['body'] = $body;
            }
            if ($updates !== []) {
                $model->update($updates);
            }
        }
    }
}
