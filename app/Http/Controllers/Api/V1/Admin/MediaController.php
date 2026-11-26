<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MediaAsset;
use App\Support\ImageCropper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    /**
     * The newest images first. Cropped copies are left out.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => MediaAsset::query()->originals()->latest('id')->limit(200)->get()->map(fn (MediaAsset $asset): array => $this->record($asset))->values(),
        ]);
    }

    /**
     * Add one image: an uploaded file (SVG is not accepted because it can carry scripts), or a link to an image
     * that is already online.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['nullable', 'required_without:url', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'url' => ['nullable', 'required_without:file', 'url:http,https', 'max:500'],
            'title' => ['nullable', 'string', 'max:150'],
            'alt' => ['nullable', 'string', 'max:250'],
        ]);

        $file = $request->file('file');
        $name = $file ? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) : pathinfo((string) parse_url($request->string('url')->toString(), PHP_URL_PATH), PATHINFO_FILENAME);

        $asset = MediaAsset::query()->create([
            'title' => $request->filled('title') ? trim($request->string('title')->toString()) : Str::limit($name ?: 'Image', 140, ''),
            'alt_text' => $request->filled('alt') ? trim($request->string('alt')->toString()) : null,
            'path' => $file ? $file->store('media', 'public') : 'external',
            'external_url' => $file ? null : $request->string('url')->toString(),
            'mime' => $file ? (string) $file->getMimeType() : 'image/external',
            'size' => $file ? $file->getSize() : 0,
            'user_id' => $request->user()->id,
        ]);

        ActivityLog::record($request->user(), 'uploaded', 'media', $asset->title);

        return response()->json(['data' => $this->record($asset)], 201);
    }

    /**
     * The picture to crop for an image address used in an editor. For the address of a cropped copy this is the
     * picture it was cut from, so it can be cropped again from the full image.
     */
    public function source(Request $request): JsonResponse
    {
        $request->validate(['url' => ['required', 'string', 'max:2000']]);

        $path = rawurldecode((string) parse_url($request->string('url')->toString(), PHP_URL_PATH));
        $asset = str_contains($path, '/storage/')
            ? MediaAsset::query()->with('parent')->where('path', Str::after($path, '/storage/'))->first()
            : null;
        $original = $asset?->parent ?? $asset;

        abort_unless($original?->isCroppable(), 404);

        return response()->json(['data' => $this->record($original)]);
    }

    /**
     * Save a rectangle of a library picture as a new file. The picture itself is not changed.
     */
    public function crop(Request $request, ImageCropper $cropper, string $id): JsonResponse
    {
        $rectangle = $request->validate([
            'x' => ['required', 'integer', 'min:0'],
            'y' => ['required', 'integer', 'min:0'],
            'width' => ['required', 'integer', 'min:10'],
            'height' => ['required', 'integer', 'min:10'],
        ]);
        [$x, $y, $width, $height] = [(int) $rectangle['x'], (int) $rectangle['y'], (int) $rectangle['width'], (int) $rectangle['height']];

        $original = MediaAsset::query()->originals()->findOrFail($id);
        $disk = Storage::disk('public');
        $contents = $original->isCroppable() ? $disk->get($original->path) : null;
        $picture = $contents === null ? null : $cropper->inspect($contents);

        if ($picture === null) {
            throw ValidationException::withMessages(['crop' => 'This image cannot be cropped.']);
        }

        if ($picture['width'] * $picture['height'] > ImageCropper::MAX_PIXELS) {
            throw ValidationException::withMessages(['crop' => 'This image is too large to crop (40 megapixels at most).']);
        }

        if ($x + $width > $picture['width'] || $y + $height > $picture['height']) {
            throw ValidationException::withMessages(['crop' => 'The crop area goes outside the image.']);
        }

        $bytes = $cropper->crop($contents, $picture, $x, $y, $width, $height);
        $path = 'media/crops/'.Str::uuid().'.'.$picture['extension'];
        $disk->put($path, $bytes);

        $asset = $original->crops()->create([
            'title' => $original->title,
            'alt_text' => $original->alt_text,
            'path' => $path,
            'mime' => $picture['mime'],
            'size' => strlen($bytes),
            'user_id' => $request->user()->id,
        ]);

        ActivityLog::record($request->user(), 'cropped', 'media', $asset->title);

        return response()->json(['data' => $this->record($asset)], 201);
    }

    /**
     * Remove a picture from the library together with its cropped copies.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $asset = MediaAsset::query()->findOrFail($id);

        if (! $asset->external_url) {
            // The database removes the cropped copies' rows with the picture; their files are removed here.
            Storage::disk('public')->delete([$asset->path, ...$asset->crops()->pluck('path')]);
        }

        ActivityLog::record($request->user(), 'deleted', 'media', $asset->title);
        $asset->delete();

        return response()->json(null, 204);
    }

    private function readableSize(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }

    /**
     * @return array{id: string, title: string, meta: string, url: string}
     */
    private function record(MediaAsset $asset): array
    {
        return [
            'id' => (string) $asset->id,
            'title' => $asset->title,
            'meta' => $asset->external_url
                ? 'LINK · '.parse_url($asset->external_url, PHP_URL_HOST)
                : Str::upper(pathinfo($asset->path, PATHINFO_EXTENSION)).' · '.$this->readableSize($asset->size),
            'url' => $asset->url(),
        ];
    }
}
