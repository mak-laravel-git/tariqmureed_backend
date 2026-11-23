<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    /**
     * The newest images first.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => MediaAsset::query()->latest('id')->limit(200)->get()->map(fn (MediaAsset $asset): array => $this->record($asset))->values(),
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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $asset = MediaAsset::query()->findOrFail($id);

        if (! $asset->external_url) {
            Storage::disk('public')->delete($asset->path);
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
