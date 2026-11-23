<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Category;
use App\Models\Writer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(6);

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'summary' => fake()->sentence(14),
            'body' => "## Opening\n\n".fake()->paragraph(4)."\n\n".fake()->paragraph(4),
            'category_id' => Category::factory(),
            'writer_id' => Writer::factory(),
            'tags' => 'cricket, analysis',
            'status' => Article::PUBLISHED,
            'published_at' => now()->subHour(),
            'views' => fake()->numberBetween(0, 500),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => Article::DRAFT, 'published_at' => null]);
    }
}
