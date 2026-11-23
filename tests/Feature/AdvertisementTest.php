<?php

use App\Enums\RoleEnum;
use App\Models\Advertisement;
use App\Models\User;
use App\Support\AdPlacements;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function signInForAds(RoleEnum $role = RoleEnum::ADMIN): void
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adPayload(array $overrides = []): array
{
    return [
        'title' => 'Summer sale',
        'subtitle' => 'home-top',
        'owner' => 'Acme',
        'status' => 'Active',
        'adType' => 'image',
        'image' => 'https://api.example.com/storage/media/banner.png',
        'linkUrl' => 'https://acme.example/sale',
        ...$overrides,
    ];
}

it('serves an empty object while no ad is running', function () {
    $this->getJson('/api/v1/ads')->assertOk()->assertExactJson(['data' => []]);
});

it('serves the running ad of each placement and nothing else', function () {
    Advertisement::factory()->create(['placement' => 'home-top', 'name' => 'Old banner']);
    Advertisement::factory()->create(['placement' => 'home-top', 'name' => 'New banner']);
    Advertisement::factory()->adsense('9876543210')->create(['placement' => 'news-sidebar']);
    Advertisement::factory()->create(['placement' => 'home-bottom', 'status' => 'Draft']);
    Advertisement::factory()->create(['placement' => 'home-stats', 'ends_at' => now()->subDay()]);
    Advertisement::factory()->create(['placement' => 'home-team', 'starts_at' => now()->addDay()]);
    Advertisement::factory()->create(['placement' => 'not-a-placement']);
    Advertisement::factory()->create(['placement' => 'legal-sidebar', 'image' => null]);

    $ads = $this->getJson('/api/v1/ads')->assertOk()->json('data');

    expect(array_keys($ads))->toEqualCanonicalizing(['home-top', 'news-sidebar'])
        ->and($ads['home-top'])->toMatchArray(['type' => 'image', 'name' => 'New banner', 'href' => 'https://example.com/offer'])
        ->and($ads['news-sidebar'])->toMatchArray(['type' => 'adsense', 'slot' => '9876543210']);
});

it('lists every placement for the admin form', function () {
    signInForAds();

    $options = $this->getJson('/api/v1/admin/advertisements/placements')->assertOk()->json('data');

    expect(array_column($options, 'key'))->toBe(AdPlacements::keys())->and($options[0])->toHaveKeys(['key', 'label', 'size']);
});

it('creates an image ad and an AdSense ad, and reads them back', function () {
    signInForAds();

    $image = $this->postJson('/api/v1/admin/advertisements', adPayload())->assertCreated()->json('data');

    expect($image)->toMatchArray(['placement' => 'home-top', 'adType' => 'image', 'subtitle' => AdPlacements::label('home-top')]);

    $this->postJson('/api/v1/admin/advertisements', adPayload([
        'title' => 'AdSense box', 'subtitle' => 'news-sidebar', 'adType' => 'adsense', 'adsenseSlot' => '1234567890', 'image' => null, 'linkUrl' => 'https://ignored.example',
    ]))->assertCreated()->assertJsonPath('data.linkUrl', null)->assertJsonPath('data.adsenseSlot', '1234567890');

    $this->getJson('/api/v1/admin/advertisements/'.$image['id'])->assertOk()->assertJsonPath('data.linkUrl', 'https://acme.example/sale');
});

it('reads ad dates in the site timezone', function () {
    config(['sportmonks.display_timezone' => 'Asia/Dhaka']);
    $this->travelTo(now()->setTime(5, 40));
    signInForAds();

    $id = $this->postJson('/api/v1/admin/advertisements', adPayload(['startsAt' => now()->setTime(11, 0)->format('Y-m-d\TH:i'), 'endsAt' => now()->setTime(18, 0)->format('Y-m-d\TH:i')]))
        ->assertCreated()
        ->assertJsonPath('data.startsAt', now()->setTime(11, 0)->format('Y-m-d\TH:i'))
        ->json('data.id');

    // 11:00 in Dhaka is 05:00 UTC, so the ad is already running at 05:40 UTC.
    expect(Advertisement::query()->running()->whereKey($id)->exists())->toBeTrue();
});

it('rejects an ad that is incomplete or points somewhere unknown', function () {
    signInForAds();

    $this->postJson('/api/v1/admin/advertisements', adPayload(['subtitle' => 'nowhere']))->assertUnprocessable()->assertJsonValidationErrors('subtitle');
    $this->postJson('/api/v1/admin/advertisements', adPayload(['image' => null]))->assertUnprocessable()->assertJsonValidationErrors('image');
    $this->postJson('/api/v1/admin/advertisements', adPayload(['adType' => 'adsense', 'adsenseSlot' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors('adsenseSlot');
    $this->postJson('/api/v1/admin/advertisements', adPayload(['linkUrl' => 'javascript:alert(1)']))->assertUnprocessable()->assertJsonValidationErrors('linkUrl');
    $this->postJson('/api/v1/admin/advertisements', adPayload(['startsAt' => '2026-12-02T10:00', 'endsAt' => '2026-12-01T10:00']))->assertUnprocessable()->assertJsonValidationErrors('endsAt');
});

it('keeps the admin ad screens behind the advertisements permission', function () {
    signInForAds(RoleEnum::USER);

    $this->getJson('/api/v1/admin/advertisements/placements')->assertForbidden();
    $this->postJson('/api/v1/admin/advertisements', adPayload())->assertForbidden();
});
