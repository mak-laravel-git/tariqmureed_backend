<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Search and share text of one website page ("/news", "/live-scores"...), edited in the admin SEO screen.
 * A page without a row (or with a blank field) uses the built-in text of that page.
 */
class SeoPage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'noindex' => 'boolean',
        ];
    }
}
