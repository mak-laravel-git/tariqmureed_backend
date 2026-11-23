<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A full story with its body blocks, tags and related stories.
 *
 * @mixin Article
 */
class ArticleDetailResource extends ArticleResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $related = Article::query()
            ->visible()
            ->with(['category', 'writer'])
            ->whereKeyNot($this->id)
            ->orderByRaw('category_id = ? desc', [$this->category_id ?? 0])
            ->latest('published_at')
            ->limit(3)
            ->get();

        return [
            ...parent::toArray($request),
            'subtitle' => $this->summary,
            'breadcrumb' => array_values(array_filter([$this->category?->name])),
            'blocks' => $this->blocks(),
            'tags' => collect(explode(',', (string) $this->tags))->map(fn (string $tag): string => trim($tag))->filter()->values()->all(),
            'related' => ArticleResource::collection($related)->resolve($request),
        ];
    }

    /**
     * Turn the plain-text body into blocks: "## Heading", "> Quote", everything else a paragraph; the first paragraph leads.
     *
     * @return array<int, array{type: string, text: string}>
     */
    private function blocks(): array
    {
        $blocks = [];

        foreach (preg_split('/\R{2,}/', trim(strip_tags((string) $this->body))) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $blocks[] = match (true) {
                Str::startsWith($chunk, '## ') => ['type' => 'heading', 'text' => trim(Str::after($chunk, '## '))],
                Str::startsWith($chunk, '> ') => ['type' => 'quote', 'text' => trim(Str::after($chunk, '> '))],
                default => ['type' => 'paragraph', 'text' => preg_replace('/\s*\R\s*/', ' ', $chunk) ?? $chunk],
            };
        }

        foreach ($blocks as $index => $block) {
            if ($block['type'] === 'paragraph') {
                $blocks[$index]['type'] = 'lead';

                break;
            }
        }

        return $blocks;
    }
}
