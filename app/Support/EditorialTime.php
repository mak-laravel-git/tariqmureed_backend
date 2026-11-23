<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Dates that editors type in the dashboard (publish dates, ad start and end dates) are in the site's own timezone,
 * the same one match times are shown in, while the database stores the application timezone.
 */
final class EditorialTime
{
    public static function timezone(): string
    {
        return (string) config('sportmonks.display_timezone');
    }

    /**
     * A typed date ("2026-11-23T11:30") converted for storage, or null when nothing was typed.
     */
    public static function toStored(?string $typed): ?CarbonInterface
    {
        return filled($typed)
            ? Date::parse($typed, self::timezone())->setTimezone((string) config('app.timezone'))
            : null;
    }

    /**
     * A stored date in the form the dashboard's date-time inputs expect.
     */
    public static function toInput(?CarbonInterface $stored): ?string
    {
        return $stored?->setTimezone(self::timezone())->format('Y-m-d\TH:i');
    }
}
