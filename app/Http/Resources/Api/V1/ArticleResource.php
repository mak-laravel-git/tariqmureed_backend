<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A story card on the public site (home page, news list, headlines).
 *
 * @mixin Article
 */
class ArticleResource extends JsonResource
{
    /** Image shown for a story that has no picture yet (a file of the Next.js app's public folder). */
    public const PLACEHOLDER_IMAGE = '/images/news/stadium-lights.jpg';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->summary ?: Str::limit(trim(strip_tags((string) $this->body)), 180),
            'image' => $this->image ?: self::PLACEHOLDER_IMAGE,
            'imageAlt' => $this->title,
            'category' => $this->category?->name ?? 'News',
            'author' => [
                'slug' => $this->writer?->slug ?? 'tmn-sport-desk',
                'name' => $this->writer?->name ?? 'TMN Sport Desk',
            ],
            'publishedAt' => $this->published_at?->toIso8601String(),
            'readMinutes' => max(1, (int) ceil(str_word_count(strip_tags((string) $this->body)) / 220)),
            'caption' => null,
            'credit' => null,
        ];
    }
}
