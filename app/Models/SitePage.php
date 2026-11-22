<?php

namespace App\Models;

use Database\Factories\SitePageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An editable legal page. A page without a row falls back to the text built into the website.
 *
 * `content` is plain text: "## Heading" starts a section, "- item" lines are bullets and the
 * other lines are paragraphs (see App\Support\LegalPageContent).
 */
class SitePage extends Model
{
    /** @use HasFactory<SitePageFactory> */
    use HasFactory;

    /** Pages the admin dashboard can edit, with the label shown there. */
    public const SLUGS = [
        'privacy-policy' => 'Privacy Policy',
        'cookie-preferences' => 'Cookie Preferences',
        'terms-of-use' => 'Terms of Use',
    ];

    protected $fillable = ['slug', 'title', 'description', 'content'];
}
