<?php

namespace Database\Seeders;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies the sample pictures from the frontend public folder into the media library,
 * so ContentSeeder and WordPressArticleSeeder can attach real library URLs to stories,
 * categories and authors instead of raw /images/... paths.
 *
 * Safe to run again: an image already stored under the same seeded path is left alone. A picture whose file went
 * missing (for example after a redeploy without a persistent storage volume) is put back: from the website's
 * folder when it sits next to the API, otherwise downloaded from the public website (FRONTEND_URL).
 */
class MediaLibrarySeeder extends Seeder
{
    /** @var array<string, string> public web path => media library URL */
    private static array $urls = [];

    /**
     * Resolve a seeded /images/... path to its media-library URL (falls back to the original path).
     */
    public static function url(string $webPath): string
    {
        $webPath = '/'.ltrim(str_replace('\\', '/', $webPath), '/');

        return self::$urls[$webPath] ?? $webPath;
    }

    /**
     * Rewrite every /images/... reference inside markdown/HTML body text.
     */
    public static function rewriteBody(string $body): string
    {
        return (string) preg_replace_callback(
            '#(/images/[^\s)"\']+)#',
            static fn (array $match): string => self::url($match[1]),
            $body,
        );
    }

    public function run(): void
    {
        $publicRoot = dirname(base_path()).DIRECTORY_SEPARATOR.'tariqmureed_frontend'.DIRECTORY_SEPARATOR.'public';

        // Rebuild the map every run so RefreshDatabase tests still get assets after the table is wiped.
        self::$urls = [];

        foreach ($this->paths() as $webPath) {
            self::$urls[$webPath] = $this->import($publicRoot, $webPath);
        }

        $this->command?->info('Media library: '.count(self::$urls).' sample images ready.');
    }

    /**
     * Every picture referenced by the content seeders (writers, sample stories, WordPress import).
     *
     * @return list<string>
     */
    private function paths(): array
    {
        $paths = [];

        foreach (ContentSeeder::writerAvatars() as $avatar) {
            if ($avatar !== '') {
                $paths[$avatar] = true;
            }
        }

        foreach (ContentSeeder::storyImages() as $image) {
            $paths[$image] = true;
        }

        $json = __DIR__.'/data/wordpress_articles.json';
        if (is_file($json)) {
            /** @var array<int, array{image?: ?string, body?: string}> $stories */
            $stories = json_decode((string) file_get_contents($json), true, flags: JSON_THROW_ON_ERROR);

            foreach ($stories as $story) {
                if (! empty($story['image'])) {
                    $paths[$story['image']] = true;
                }

                if (! empty($story['body']) && preg_match_all('#\((/images/[^)]+)\)#', $story['body'], $matches)) {
                    foreach ($matches[1] as $embedded) {
                        $paths[$embedded] = true;
                    }
                }
            }
        }

        ksort($paths);

        return array_keys($paths);
    }

    private function import(string $publicRoot, string $webPath): string
    {
        $relative = ltrim(str_replace('\\', '/', $webPath), '/');
        $source = $publicRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destPath = 'media/seeded/'.preg_replace('#^images/#', '', $relative);
        $disk = Storage::disk('public');
        $existing = MediaAsset::query()->where('path', $destPath)->first();

        if ($existing && $disk->exists($destPath)) {
            return $existing->url();
        }

        $contents = $this->contents($source, $relative);

        if ($contents === null) {
            $this->command?->warn("Missing sample image: {$webPath}");

            return $existing?->url() ?? $webPath;
        }

        $disk->put($destPath, $contents);

        if ($existing) {
            $existing->update(['size' => strlen($contents)]);

            return $existing->url();
        }

        $title = Str::of(pathinfo($relative, PATHINFO_FILENAME))
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->limit(140, '')
            ->toString();

        $asset = MediaAsset::query()->create([
            'title' => $title !== '' ? $title : 'Seeded image',
            'alt_text' => $title !== '' ? $title : null,
            'path' => $destPath,
            'external_url' => null,
            'mime' => (string) ((new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'image/jpeg'),
            'size' => strlen($contents),
            'user_id' => User::query()->orderBy('id')->value('id'),
        ]);

        return $asset->url();
    }

    /**
     * The picture's bytes: the website's own file when it is on this machine, else the public website's copy.
     */
    private function contents(string $source, string $relative): ?string
    {
        if (is_file($source)) {
            return (string) file_get_contents($source);
        }

        $base = rtrim((string) config('app.frontend_url'), '/');

        if ($base === '') {
            return null;
        }

        try {
            $response = Http::timeout(30)->get($base.'/'.implode('/', array_map('rawurlencode', explode('/', $relative))));
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/') && $response->body() !== ''
            ? $response->body()
            : null;
    }
}
