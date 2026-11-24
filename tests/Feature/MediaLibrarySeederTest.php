<?php

use App\Models\MediaAsset;
use Database\Seeders\MediaLibrarySeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
})->skip(
    fn (): bool => ! is_dir(dirname(base_path()).'/tariqmureed_frontend/public/images'),
    'The website folder is not next to the API here.',
);

it('puts a seeded picture back when its file went missing, without a second library row', function () {
    $this->seed(MediaLibrarySeeder::class);

    $asset = MediaAsset::query()->where('path', 'like', 'media/seeded/%')->firstOrFail();
    $count = MediaAsset::query()->count();

    expect(Storage::disk('public')->exists($asset->path))->toBeTrue();

    Storage::disk('public')->delete($asset->path);
    expect(Storage::disk('public')->exists($asset->path))->toBeFalse();

    $this->seed(MediaLibrarySeeder::class);

    expect(Storage::disk('public')->exists($asset->path))->toBeTrue()
        ->and(MediaAsset::query()->count())->toBe($count)
        ->and($asset->fresh()->size)->toBeGreaterThan(0);
});
