<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use GeneratesUniqueSlug, HasFactory;

    public const DRAFT = 'Draft';

    public const PENDING = 'Pending';

    public const SCHEDULED = 'Scheduled';

    public const PUBLISHED = 'Published';

    public const ARCHIVED = 'Archived';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'publish_at' => 'datetime',
            'published_at' => 'datetime',
            'gallery' => 'array',
        ];
    }

    /**
     * Articles visitors can read: published, or scheduled for a time that has passed.
     *
     * @param  Builder<Article>  $query
     * @return Builder<Article>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::PUBLISHED, self::SCHEDULED])
            ->where('published_at', '<=', now());
    }

    /**
     * Keep published_at in step with the status: set when the story goes (or is scheduled to go) live.
     */
    public function syncPublishedAt(): void
    {
        $this->published_at = match ($this->status) {
            self::PUBLISHED => $this->publish_at ?? ($this->published_at?->isPast() ? $this->published_at : now()),
            self::SCHEDULED => $this->publish_at ?? now(),
            default => null,
        };
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Writer, $this>
     */
    public function writer(): BelongsTo
    {
        return $this->belongsTo(Writer::class);
    }
}
