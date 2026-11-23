<?php

namespace App\Models;

use Database\Factories\AdvertisementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An advertisement shown on one placement of the public website (an image banner or a Google AdSense unit).
 */
class Advertisement extends Model
{
    /** @use HasFactory<AdvertisementFactory> */
    use HasFactory;

    public const TYPE_IMAGE = 'image';

    public const TYPE_ADSENSE = 'adsense';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Ads that are switched on and inside their start and end dates right now.
     *
     * @param  Builder<Advertisement>  $query
     * @return Builder<Advertisement>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query
            ->where('status', 'Active')
            ->where(fn ($inner) => $inner->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($inner) => $inner->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
