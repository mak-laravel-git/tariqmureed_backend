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
     * Upload one image. SVG is not accepted because it can carry scripts.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240']]);

        $file = $request->file('file');
        $asset = MediaAsset::query()->create([
            'title' => Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 140, ''),
            'path' => $file->store('media', 'public'),
            'mime' => (string) $file->getMimeType(),
            'size' => $file->getSize(),
            'user_id' => $request->user()->id,
        ]);

        ActivityLog::record($request->user(), 'uploaded', 'media', $asset->title);

        return response()->json(['data' => $this->record($asset)], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $asset = MediaAsset::query()->findOrFail($id);

        Storage::disk('public')->delete($asset->path);
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
            'meta' => Str::upper(pathinfo($asset->path, PATHINFO_EXTENSION)).' · '.$this->readableSize($asset->size),
            'url' => $asset->url(),
        ];
    }
}
