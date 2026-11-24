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
            'seoTitle' => $this->seo_title ?: null,
            'seoDescription' => $this->seo_description ?: null,
            'socialImage' => $this->social_image ?: null,
            'gallery' => array_values($this->gallery ?? []),
            'tags' => collect(explode(',', (string) $this->tags))->map(fn (string $tag): string => trim($tag))->filter()->values()->all(),
            'related' => ArticleResource::collection($related)->resolve($request),
        ];
    }

    /**
     * Turn the plain-text body into blocks. The editor toolbar writes these: "# / ## Heading", "> Quote", "- item" and
     * "1. item" lists, "![alt](url)" images, "[video](url)" videos and "| a | b |" tables. Everything else is a
     * paragraph (the first one leads); bold, italic, underline and links inside a paragraph are drawn by the site.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocks(): array
    {
        $blocks = [];

        foreach (preg_split('/\R{2,}/', trim(strip_tags((string) $this->body))) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $lines = preg_split('/\R/', $chunk) ?: [$chunk];

            $blocks[] = match (true) {
                (bool) preg_match('/^#{1,3} /', $chunk) => ['type' => 'heading', 'text' => trim((string) preg_replace('/^#{1,3} /', '', $chunk))],
                Str::startsWith($chunk, '> ') => ['type' => 'quote', 'text' => trim(Str::after($chunk, '> '))],
                $this->allLines($lines, '/^[-*] /') => ['type' => 'list', 'ordered' => false, 'items' => $this->listItems($lines, '/^[-*] /')],
                $this->allLines($lines, '/^\d+[.)] /') => ['type' => 'list', 'ordered' => true, 'items' => $this->listItems($lines, '/^\d+[.)] /')],
                (bool) preg_match('/^!\[([^\]]*)\]\(((?:https?:\/\/|\/)[^)\s]+)\)$/', $chunk, $image) => ['type' => 'image', 'text' => $image[1], 'src' => $image[2]],
                (bool) preg_match('/^\[video\]\((https?:\/\/[^)\s]+)\)$/i', $chunk, $video) => ['type' => 'video', 'text' => 'Video', 'src' => $video[1]],
                $this->allLines($lines, '/^\|.*\|$/') => ['type' => 'table', 'text' => '', 'rows' => $this->tableRows($lines)],
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

    /**
     * @param  array<int, string>  $lines
     */
    private function allLines(array $lines, string $pattern): bool
    {
        return collect($lines)->every(fn (string $line): bool => (bool) preg_match($pattern, trim($line)));
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private function listItems(array $lines, string $marker): array
    {
        return array_map(fn (string $line): string => trim((string) preg_replace($marker, '', trim($line))), $lines);
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array<int, string>>
     */
    private function tableRows(array $lines): array
    {
        return collect($lines)
            ->reject(fn (string $line): bool => (bool) preg_match('/^\|[\s:|-]+\|$/', trim($line)))
            ->map(fn (string $line): array => array_map('trim', explode('|', trim(trim($line), '|'))))
            ->values()
            ->all();
    }
}
