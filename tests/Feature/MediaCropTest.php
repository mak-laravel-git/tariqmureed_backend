<?php

use App\Enums\RoleEnum;
use App\Models\MediaAsset;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('public');
});

function signInForCropping(RoleEnum $role = RoleEnum::ADMIN): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * An 80 x 40 picture in four colours: red and green on top, blue and white below.
 */
function quadrantPicture(string $type = 'jpeg'): string
{
    $image = imagecreatetruecolor(80, 40);

    foreach ([[0, 0, 0xFF0000], [40, 0, 0x00FF00], [0, 20, 0x0000FF], [40, 20, 0xFFFFFF]] as [$x, $y, $colour]) {
        imagefilledrectangle($image, $x, $y, $x + 39, $y + 19, $colour);
    }

    ob_start();
    $type === 'png' ? imagepng($image) : imagejpeg($image, null, 100);

    return (string) ob_get_clean();
}

/** The same JPEG carrying a camera's "which way up" tag. */
function withExifOrientation(string $jpeg, int $orientation): string
{
    $tiff = 'II'.pack('vV', 42, 8).pack('v', 1).pack('vvVvv', 0x0112, 3, 1, $orientation, 0).pack('V', 0);
    $segment = "Exif\0\0".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 2);
}

function colourAt(GdImage $image, int $x, int $y): string
{
    ['red' => $red, 'green' => $green, 'blue' => $blue] = imagecolorsforindex($image, imagecolorat($image, $x, $y));

    return match ([$red > 128, $green > 128, $blue > 128]) {
        [true, false, false] => 'red',
        [false, true, false] => 'green',
        [false, false, true] => 'blue',
        [true, true, true] => 'white',
        default => 'other',
    };
}

function libraryPicture(string $contents, string $path = 'media/match.jpg', string $mime = 'image/jpeg'): MediaAsset
{
    Storage::disk('public')->put($path, $contents);

    return MediaAsset::factory()->create(['title' => 'Match day', 'path' => $path, 'mime' => $mime, 'size' => strlen($contents)]);
}

it('saves only the chosen rectangle as a new file and leaves the original alone', function () {
    $user = signInForCropping();
    $contents = quadrantPicture();
    $original = libraryPicture($contents);

    $response = $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 40, 'y' => 20, 'width' => 30, 'height' => 15])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Match day');

    $crop = MediaAsset::query()->findOrFail($response->json('data.id'));
    $image = imagecreatefromstring(Storage::disk('public')->get($crop->path));

    expect($crop->parent_id)->toBe($original->id)
        ->and($crop->user_id)->toBe($user->id)
        ->and($crop->path)->toStartWith('media/crops/')->toEndWith('.jpg')
        ->and($response->json('data.url'))->toEndWith('/storage/'.$crop->path)
        ->and([imagesx($image), imagesy($image)])->toBe([30, 15])
        ->and(colourAt($image, 15, 7))->toBe('white')
        ->and(Storage::disk('public')->get($original->path))->toBe($contents);
});

it('leaves cropped copies out of the library and its count', function () {
    signInForCropping();
    $original = libraryPicture(quadrantPicture());
    $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20])->assertCreated();

    $this->getJson('/api/v1/admin/media')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $original->id);
    $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonFragment(['label' => 'Media assets', 'value' => '1']);
});

it('keeps the transparency of a PNG', function () {
    signInForCropping();
    $image = imagecreatetruecolor(40, 40);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);
    $original = libraryPicture((string) ob_get_clean(), 'media/logo.png', 'image/png');

    $id = $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20])->assertCreated()->json('data.id');

    $cropped = imagecreatefromstring(Storage::disk('public')->get(MediaAsset::query()->findOrFail($id)->path));
    expect(imagecolorsforindex($cropped, imagecolorat($cropped, 5, 5))['alpha'])->toBe(127);
});

it('measures the rectangle on the photo as the browser shows it', function (int $orientation, int $width, int $height, string $topLeft, string $topRight) {
    signInForCropping();
    $original = libraryPicture(withExifOrientation(quadrantPicture(), $orientation));

    $id = $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height])->assertCreated()->json('data.id');

    $image = imagecreatefromstring(Storage::disk('public')->get(MediaAsset::query()->findOrFail($id)->path));
    expect(colourAt($image, intdiv($width, 4), intdiv($height, 4)))->toBe($topLeft)
        ->and(colourAt($image, intdiv($width * 3, 4), intdiv($height, 4)))->toBe($topRight);
})->with([
    'upright' => [1, 80, 40, 'red', 'green'],
    'mirrored' => [2, 80, 40, 'green', 'red'],
    'upside down' => [3, 80, 40, 'white', 'blue'],
    'flipped' => [4, 80, 40, 'blue', 'white'],
    'transposed' => [5, 40, 80, 'red', 'blue'],
    'quarter turn clockwise' => [6, 40, 80, 'blue', 'red'],
    'transversed' => [7, 40, 80, 'white', 'green'],
    'quarter turn anticlockwise' => [8, 40, 80, 'green', 'white'],
]);

it('rejects a rectangle that is too small or outside the picture', function (array $rectangle, string $field) {
    signInForCropping();
    $original = libraryPicture(quadrantPicture());

    $this->postJson("/api/v1/admin/media/{$original->id}/crop", $rectangle)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($original->crops()->count())->toBe(0);
})->with([
    'past the right edge' => [['x' => 70, 'y' => 0, 'width' => 20, 'height' => 20], 'crop'],
    'past the bottom edge' => [['x' => 0, 'y' => 30, 'width' => 20, 'height' => 20], 'crop'],
    'too narrow' => [['x' => 0, 'y' => 0, 'width' => 9, 'height' => 20], 'width'],
    'too low' => [['x' => 0, 'y' => 0, 'width' => 20, 'height' => 9], 'height'],
    'negative start' => [['x' => -1, 'y' => 0, 'width' => 20, 'height' => 20], 'x'],
    'nothing chosen' => [[], 'width'],
]);

it('rejects a picture with more than 40 megapixels', function () {
    signInForCropping();
    // Only the header is needed: the size is checked before the picture is opened.
    $header = 'IHDR'.pack('NNCCCCC', 8000, 5001, 8, 2, 0, 0, 0);
    $original = libraryPicture("\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header)), 'media/huge.png', 'image/png');

    $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['crop' => 'too large']);
});

it('refuses to crop a GIF or a linked image', function (array $attributes) {
    signInForCropping();
    $asset = MediaAsset::factory()->create($attributes);

    $this->postJson("/api/v1/admin/media/{$asset->id}/crop", ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20])->assertUnprocessable()->assertJsonValidationErrors('crop');
    $this->getJson('/api/v1/admin/media/source?url='.urlencode($asset->url()))->assertNotFound();
})->with([
    'GIF' => [['path' => 'media/loop.gif', 'mime' => 'image/gif']],
    'linked image' => [['path' => 'external', 'mime' => 'image/external', 'external_url' => 'https://cdn.example.com/photo.jpg']],
]);

it('finds the full picture behind the address of the original or of a cropped copy', function () {
    signInForCropping();
    $original = libraryPicture(quadrantPicture());
    $cropUrl = $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20])->json('data.url');

    foreach ([$original->url(), $cropUrl] as $url) {
        $this->getJson('/api/v1/admin/media/source?url='.urlencode($url))
            ->assertOk()
            ->assertJsonPath('data.id', (string) $original->id)
            ->assertJsonPath('data.url', $original->url());
    }

    $this->getJson('/api/v1/admin/media/source?url='.urlencode('https://cdn.example.com/other.jpg'))->assertNotFound();
});

it('returns 404 when asked to crop a cropped copy', function () {
    signInForCropping();
    $original = libraryPicture(quadrantPicture());
    $id = $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 0, 'y' => 0, 'width' => 40, 'height' => 20])->json('data.id');

    $this->postJson("/api/v1/admin/media/{$id}/crop", ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20])->assertNotFound();
});

it('removes the cropped copies together with their picture', function () {
    signInForCropping();
    $original = libraryPicture(quadrantPicture());
    $id = $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20])->json('data.id');
    $crop = MediaAsset::query()->findOrFail($id);

    $this->deleteJson("/api/v1/admin/media/{$original->id}")->assertNoContent();

    Storage::disk('public')->assertMissing([$original->path, $crop->path]);
    $this->assertModelMissing($crop);
});

it('forbids cropping without media permissions', function () {
    signInForCropping(RoleEnum::USER);
    $original = libraryPicture(quadrantPicture());

    $this->postJson("/api/v1/admin/media/{$original->id}/crop", ['x' => 0, 'y' => 0, 'width' => 20, 'height' => 20])->assertForbidden();
    $this->getJson('/api/v1/admin/media/source?url='.urlencode($original->url()))->assertForbidden();
});
