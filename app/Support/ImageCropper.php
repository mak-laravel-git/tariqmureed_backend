<?php

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Cuts a rectangle out of a stored picture with GD. The cut keeps its own pixels (nothing is resized) and the
 * file type of the original. Coordinates refer to the picture as a browser shows it, i.e. after the camera's
 * EXIF rotation.
 */
final class ImageCropper
{
    /** File types that can be cropped (GIF is left out because cropping would drop its animation). */
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Larger pictures need more memory than a web request should take. */
    public const MAX_PIXELS = 40_000_000;

    private const MEMORY_LIMIT = '512M';

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Size and type of a picture, or null when it cannot be cropped (unknown or animated file).
     *
     * @return array{width: int, height: int, mime: string, extension: string, orientation: int}|null
     */
    public function inspect(string $contents): ?array
    {
        $info = @getimagesizefromstring($contents);

        if ($info === false || ! in_array($info['mime'], self::MIMES, true) || $this->isAnimatedWebp($contents)) {
            return null;
        }

        $orientation = $info['mime'] === 'image/jpeg' ? $this->orientation($contents) : 1;
        $sideways = $orientation >= 5;

        return [
            'width' => $sideways ? $info[1] : $info[0],
            'height' => $sideways ? $info[0] : $info[1],
            'mime' => $info['mime'],
            'extension' => self::EXTENSIONS[$info['mime']],
            'orientation' => $orientation,
        ];
    }

    /**
     * The encoded bytes of the chosen rectangle. Call inspect() first and keep the rectangle inside its size.
     *
     * @param  array{mime: string, orientation: int}  $picture
     */
    public function crop(string $contents, array $picture, int $x, int $y, int $width, int $height): string
    {
        $this->raiseMemoryLimit();

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException('The picture could not be read.');
        }

        imagepalettetotruecolor($source);
        $source = $this->upright($source, $picture['orientation']);

        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopy($target, $source, 0, 0, $x, $y, $width, $height);
        unset($source);

        ob_start();

        $written = match ($picture['mime']) {
            'image/png' => imagepng($target),
            'image/webp' => imagewebp($target, null, 90),
            default => imagejpeg($target, null, 92),
        };

        $bytes = (string) ob_get_clean();

        if (! $written || $bytes === '') {
            throw new RuntimeException('The cropped picture could not be saved.');
        }

        return $bytes;
    }

    /**
     * The EXIF orientation of a photo (1 = already upright).
     */
    private function orientation(string $contents): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $orientation = (int) ($exif['Orientation'] ?? 1);

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function upright(GdImage $image, int $orientation): GdImage
    {
        // imagerotate() turns counter-clockwise.
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $image = imagerotate($image, $angle, 0);
        }

        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        return $image;
    }

    private function isAnimatedWebp(string $contents): bool
    {
        // Extended WebP files declare animation with a flag bit in the VP8X header.
        return substr($contents, 12, 4) === 'VP8X' && (ord($contents[20] ?? "\0") & 0x02) !== 0;
    }

    private function raiseMemoryLimit(): void
    {
        $current = ini_parse_quantity((string) ini_get('memory_limit'));

        if ($current > 0 && $current < ini_parse_quantity(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }
    }
}
