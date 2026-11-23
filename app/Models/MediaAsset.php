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
     * Public URL of the stored file. It follows the address the dashboard is opened on, so it stays correct
     * (and https in production) even when APP_URL is missing or wrong.
     */
    public function url(): string
    {
        return url('storage/'.ltrim($this->path, '/'));
    }
}
