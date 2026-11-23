<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Writer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An author on the public "Writers" pages.
 *
 * @mixin Writer
 */
class WriterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'title' => (string) $this->title,
            'bio' => (string) $this->bio,
            'avatar' => $this->avatar ?: '/images/brand/logo.png',
            'articleCount' => (int) ($this->visible_articles_count ?? $this->articles()->visible()->count()),
            'since' => $this->created_at->year,
        ];
    }
}
