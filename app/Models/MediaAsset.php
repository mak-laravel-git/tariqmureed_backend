<?php

namespace App\Models;

use App\Support\ImageCropper;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The library picture a cropped copy was cut from.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Cropped copies of this picture. They are used on pages but not listed in the library.
     *
     * @return HasMany<MediaAsset, $this>
     */
    public function crops(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Pictures added to the library, without the cropped copies.
     *
     * @param  Builder<MediaAsset>  $query
     */
    #[Scope]
    protected function originals(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Only a stored JPG, PNG or WebP file can be cropped; a linked image is not ours to cut.
     */
    public function isCroppable(): bool
    {
        return ! $this->external_url && in_array($this->mime, ImageCropper::MIMES, true);
    }

    /**
     * Public URL of the image: the linked address for an image added by URL, otherwise the stored file. A stored
     * file's URL follows the address the dashboard is opened on, so it stays correct (and https in production)
     * even when APP_URL is missing or wrong.
     */
    public function url(): string
    {
        return $this->external_url ?: url('storage/'.ltrim($this->path, '/'));
    }
}
