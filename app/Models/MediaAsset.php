<?php

namespace App\Models;

use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected $guarded = [];

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
