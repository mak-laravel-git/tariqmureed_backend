<?php

namespace App\Models;

use App\Models\Concerns\GeneratesUniqueSlug;
use Database\Factories\WriterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An author credited on articles (the "Authors" section of the admin dashboard).
 */
class Writer extends Model
{
    /** @use HasFactory<WriterFactory> */
    use GeneratesUniqueSlug, HasFactory;

    protected $guarded = [];

    /**
     * @return HasMany<Article, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
