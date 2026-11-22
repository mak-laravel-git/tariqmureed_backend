<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Website settings edited from the admin dashboard and stored in the `settings` table.
 *
 * Empty tracking values mean "not configured": the website then falls back to its own
 * environment variables, so nothing is lost when a field is left blank.
 */
final class SiteSettings
{
    /** @var array<string, string|bool> setting => default (the type of the default is the type of the value) */
    private const DEFAULTS = [
        'websiteName' => 'TMN Sport',
        'contactEmail' => '',
        'contactAddress' => '',
        'description' => '',
        'defaultStatus' => 'Draft',
        'timezone' => 'Asia/Dhaka (UTC+6)',
        'requireApproval' => true,
        'notifyEditors' => true,
        'gaMeasurementId' => '',
        'adsenseClientId' => '',
        'consentMode' => 'builtin',
    ];

    /** Settings the public website may read (identifiers and contact details are public anyway). */
    private const PUBLIC = [
        'websiteName', 'contactEmail', 'contactAddress', 'gaMeasurementId', 'adsenseClientId', 'consentMode',
    ];

    /**
     * @return array<string, string|bool>
     */
    public static function all(): array
    {
        $stored = Setting::query()->pluck('value', 'key');
        $values = [];

        foreach (self::DEFAULTS as $key => $default) {
            if (! $stored->has($key)) {
                $values[$key] = $default;

                continue;
            }

            $values[$key] = is_bool($default) ? $stored[$key] === '1' : (string) $stored[$key];
        }

        return $values;
    }

    /**
     * @return array<string, string|bool>
     */
    public static function public(): array
    {
        return array_intersect_key(self::all(), array_flip(self::PUBLIC));
    }

    /**
     * @param  array<string, mixed>  $values  Unknown keys are ignored.
     */
    public static function update(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value],
            );
        }
    }
}
