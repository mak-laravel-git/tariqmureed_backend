<?php

namespace App\Support;

/**
 * SportMonks does not supply team colours, so every team gets a stable gradient
 * derived from its name. The same team always renders with the same badge.
 */
final class TeamColors
{
    /**
     * @return array{from: string, to: string}
     */
    public static function for(string $seed): array
    {
        $hue = crc32(mb_strtolower($seed)) % 360;

        return [
            'from' => sprintf('hsl(%d, 68%%, 42%%)', $hue),
            'to' => sprintf('hsl(%d, 72%%, 28%%)', ($hue + 24) % 360),
        ];
    }

    public static function shortName(string $name, ?string $code): string
    {
        $code = trim((string) $code);

        if ($code !== '') {
            return mb_strtoupper(mb_substr($code, 0, 4));
        }

        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->map(fn (string $word): string => mb_substr($word, 0, 1))
            ->implode('');

        return mb_strtoupper(mb_substr($initials !== '' ? $initials : $name, 0, 3));
    }
}
