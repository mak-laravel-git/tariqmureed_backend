<?php

use App\Enums\RoleEnum;
use App\Models\SitePage;
use App\Models\User;
use App\Support\LegalPageContent;
use App\Support\SiteSettings;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function actingAsStaff(RoleEnum $role = RoleEnum::ADMIN): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);

    return $user;
}

it('turns admin text into sections, paragraphs and bullets', function () {
    $sections = LegalPageContent::parse(<<<'TEXT'
    Ignored intro
    ## Who we are
    First line
    still the same paragraph.

    Second paragraph with [a link](https://example.com).

    ## What we collect
    Intro line
    - IP address
    - Pages visited
    TEXT);

    expect($sections)->toBe([
        ['heading' => 'Who we are', 'paragraphs' => ['First line still the same paragraph.', 'Second paragraph with [a link](https://example.com).']],
        ['heading' => 'What we collect', 'paragraphs' => ['Intro line'], 'bullets' => ['IP address', 'Pages visited']],
    ]);
});

it('serves default public settings before anything is saved', function () {
    $this->getJson('/api/v1/settings')
        ->assertOk()
        ->assertExactJson(['data' => [
            'websiteName' => 'TMN Sport',
            'contactEmail' => '',
            'contactAddress' => '',
            'gaMeasurementId' => '',
            'adsenseClientId' => '',
            'consentMode' => 'builtin',
            'seoSiteUrl' => '',
            'seoTitleTemplate' => '%s | TMN Sport',
            'seoDefaultImage' => '',
            'seoTwitterHandle' => '',
            'seoIndexing' => true,
            'seoGoogleVerification' => '',
            'seoBingVerification' => '',
        ]]);
});

it('keeps editorial settings out of the public settings', function () {
    SiteSettings::update(['requireApproval' => false, 'description' => 'Internal note']);

    $payload = $this->getJson('/api/v1/settings')->assertOk()->json('data');

    expect($payload)->not->toHaveKeys(['requireApproval', 'description', 'notifyEditors', 'defaultStatus']);
});

it('requires a token and the right permission for the admin settings', function () {
    $this->getJson('/api/v1/admin/settings')->assertUnauthorized();
    $this->putJson('/api/v1/admin/settings', [])->assertUnauthorized();

    actingAsStaff(RoleEnum::USER);

    $this->getJson('/api/v1/admin/settings')->assertForbidden();
    $this->putJson('/api/v1/admin/settings', ['websiteName' => 'Hacked'])->assertForbidden();
});

it('saves settings from the dashboard and shows them publicly', function () {
    actingAsStaff();

    $this->putJson('/api/v1/admin/settings', [
        'websiteName' => 'TMN Cricket',
        'contactEmail' => 'press@tmnsport.com',
        'contactAddress' => '12 Cricket Road, Dhaka',
        'gaMeasurementId' => 'G-ABCD123456',
        'adsenseClientId' => 'ca-pub-1234567890123456',
        'consentMode' => 'google',
        'requireApproval' => false,
        'defaultStatus' => 'Pending',
    ])
        ->assertOk()
        ->assertJsonPath('data.gaMeasurementId', 'G-ABCD123456')
        ->assertJsonPath('data.requireApproval', false);

    $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('data.defaultStatus', 'Pending');

    $this->getJson('/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('data.contactEmail', 'press@tmnsport.com')
        ->assertJsonPath('data.adsenseClientId', 'ca-pub-1234567890123456')
        ->assertJsonPath('data.consentMode', 'google');
});

it('clears a tracking id when the field is left blank', function () {
    actingAsStaff();
    SiteSettings::update(['gaMeasurementId' => 'G-ABCD123456']);

    $this->putJson('/api/v1/admin/settings', ['gaMeasurementId' => ''])->assertOk();

    expect(SiteSettings::all()['gaMeasurementId'])->toBe('');
});

it('rejects malformed tracking ids and unknown consent modes', function () {
    actingAsStaff();

    $this->putJson('/api/v1/admin/settings', [
        'gaMeasurementId' => 'UA-1234',
        'adsenseClientId' => 'pub-123',
        'consentMode' => 'none',
        'contactEmail' => 'not-an-email',
    ])->assertUnprocessable()->assertJsonValidationErrors(['gaMeasurementId', 'adsenseClientId', 'consentMode', 'contactEmail']);
});

it('answers 404 for a page that was never customised', function () {
    $this->getJson('/api/v1/pages/privacy-policy')->assertNotFound();
});

it('publishes an edited page with sections and the contact details', function () {
    actingAsStaff();
    SiteSettings::update(['contactEmail' => 'press@tmnsport.com', 'contactAddress' => 'Dhaka']);

    $this->putJson('/api/v1/admin/pages/privacy-policy', [
        'title' => 'Privacy Policy',
        'description' => 'How we handle data.',
        'content' => "## Who we are\n\nWe run TMN Sport.\n\n## Cookies\n\n- Analytics\n- Advertising",
    ])->assertOk()->assertJsonPath('data.slug', 'privacy-policy');

    $this->getJson('/api/v1/pages/privacy-policy')
        ->assertOk()
        ->assertJsonPath('data.title', 'Privacy Policy')
        ->assertJsonPath('data.sections.0.heading', 'Who we are')
        ->assertJsonPath('data.sections.0.paragraphs.0', 'We run TMN Sport.')
        ->assertJsonPath('data.sections.1.bullets', ['Analytics', 'Advertising'])
        ->assertJsonPath('data.contact.email', 'press@tmnsport.com')
        ->assertJsonPath('data.privacySettings', true);
});

it('updates an existing page instead of duplicating it', function () {
    actingAsStaff();
    SitePage::factory()->create(['slug' => 'terms-of-use', 'title' => 'Old']);

    $this->putJson('/api/v1/admin/pages/terms-of-use', ['title' => 'New', 'content' => "## One\n\nText"])->assertOk();

    expect(SitePage::query()->where('slug', 'terms-of-use')->count())->toBe(1)
        ->and(SitePage::query()->where('slug', 'terms-of-use')->value('title'))->toBe('New');
});

it('lists the editable pages and which ones are customised', function () {
    actingAsStaff();
    SitePage::factory()->create(['slug' => 'cookie-preferences']);

    $pages = collect($this->getJson('/api/v1/admin/pages')->assertOk()->json('data'))->keyBy('slug');

    expect($pages->keys()->all())->toBe(['privacy-policy', 'cookie-preferences', 'terms-of-use'])
        ->and($pages['cookie-preferences']['customised'])->toBeTrue()
        ->and($pages['privacy-policy']['customised'])->toBeFalse();
});

it('returns 404 from the admin when a page has no saved text yet', function () {
    actingAsStaff();

    $this->getJson('/api/v1/admin/pages/privacy-policy')->assertNotFound();
});

it('resets a page to the built-in text by deleting the saved one', function () {
    actingAsStaff();
    SitePage::factory()->create();

    $this->deleteJson('/api/v1/admin/pages/privacy-policy')->assertOk();

    $this->getJson('/api/v1/pages/privacy-policy')->assertNotFound();
});

it('refuses to edit a page that is not on the allowed list', function () {
    actingAsStaff();

    $this->putJson('/api/v1/admin/pages/secret-page', ['title' => 'x', 'content' => '## a'])->assertNotFound();
    expect(SitePage::query()->count())->toBe(0);
});

it('needs a heading before a page can be saved', function () {
    actingAsStaff();

    $this->putJson('/api/v1/admin/pages/privacy-policy', ['title' => 'Privacy', 'content' => 'No headings here'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');
});

it('forbids staff without page permissions from editing pages', function () {
    actingAsStaff(RoleEnum::USER);

    $this->putJson('/api/v1/admin/pages/privacy-policy', ['title' => 'x', 'content' => '## a'])->assertForbidden();
    $this->getJson('/api/v1/admin/pages')->assertForbidden();
});

it('lets the SportMonks admin page reach the integration under the admin prefix', function () {
    actingAsStaff();

    $this->getJson('/api/v1/admin/integrations/sportmonks')->assertOk()->assertJsonPath('data.provider', 'SportMonks');
});
