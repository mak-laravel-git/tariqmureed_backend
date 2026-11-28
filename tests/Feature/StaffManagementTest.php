<?php

use App\Enums\RoleEnum;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function staffAccount(RoleEnum $role = RoleEnum::USER, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role->value);

    return $user;
}

function signInStaffManager(RoleEnum $role = RoleEnum::ADMIN, array $attributes = []): User
{
    $user = staffAccount($role, $attributes);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * @return array<string, string>
 */
function newStaffForm(array $overrides = []): array
{
    return [
        'title' => 'Nadia Rahman',
        'subtitle' => 'nadia@example.com',
        'owner' => 'admin',
        'password' => 'first-password-1',
        'password_confirmation' => 'first-password-1',
        ...$overrides,
    ];
}

it('creates a staff account that can sign in with the chosen password and role', function () {
    $admin = signInStaffManager();

    $this->postJson('/api/v1/admin/staff', newStaffForm())
        ->assertCreated()
        ->assertJsonPath('data.title', 'Nadia Rahman')
        ->assertJsonPath('data.owner', 'Admin')
        ->assertJsonPath('data.role', 'admin');

    $created = User::query()->where('email', 'nadia@example.com')->sole();

    expect(Hash::check('first-password-1', $created->password))->toBeTrue()
        ->and($created->getRoleNames()->all())->toBe(['admin']);

    $this->assertDatabaseHas('activity_logs', ['user_id' => $admin->id, 'action' => 'created', 'subject' => 'staff account', 'subject_id' => $created->id]);
    $this->postJson('/api/v1/auth/login', ['email' => 'nadia@example.com', 'password' => 'first-password-1', 'device_name' => 'test'])->assertOk();
});

it('refuses an unusable new account', function (array $overrides, string $field) {
    signInStaffManager(attributes: ['email' => 'taken@example.com']);

    $this->postJson('/api/v1/admin/staff', newStaffForm($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(User::query()->count())->toBe(1);
})->with([
    'no name' => [['title' => ''], 'title'],
    'a bad email' => [['subtitle' => 'nadia'], 'subtitle'],
    'an email in use' => [['subtitle' => 'taken@example.com'], 'subtitle'],
    'an unknown role' => [['owner' => 'editor'], 'owner'],
    'no password' => [['password' => '', 'password_confirmation' => ''], 'password'],
    'a short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'passwords that differ' => [['password_confirmation' => 'something-else-1'], 'password'],
]);

it('lets only a super admin create a super admin', function () {
    signInStaffManager(RoleEnum::ADMIN);

    $this->postJson('/api/v1/admin/staff', newStaffForm(['owner' => 'super-admin']))->assertForbidden();
    expect(User::query()->count())->toBe(1);

    signInStaffManager(RoleEnum::SUPER_ADMIN);

    $this->postJson('/api/v1/admin/staff', newStaffForm(['owner' => 'super-admin']))->assertCreated()->assertJsonPath('data.owner', 'Super Admin');
});

it('needs the permission to create staff', function () {
    signInStaffManager(RoleEnum::USER);

    $this->postJson('/api/v1/admin/staff', newStaffForm())->assertForbidden();
});

it('keeps the password when an edit leaves it empty', function () {
    signInStaffManager();
    $member = staffAccount(attributes: ['password' => 'old-password-1']);
    $member->createToken('their-browser');

    $this->putJson("/api/v1/admin/staff/{$member->id}", ['title' => 'Renamed', 'subtitle' => $member->email, 'owner' => 'user', 'password' => '', 'password_confirmation' => ''])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed');

    expect(Hash::check('old-password-1', $member->refresh()->password))->toBeTrue()
        ->and($member->tokens()->count())->toBe(1);
});

it('sets a new password for a staff member and signs them out', function () {
    signInStaffManager();
    $member = staffAccount(attributes: ['password' => 'old-password-1']);
    $member->createToken('their-browser');

    $this->putJson("/api/v1/admin/staff/{$member->id}", ['title' => $member->name, 'subtitle' => $member->email, 'owner' => 'user', 'password' => 'new-password-2', 'password_confirmation' => 'new-password-2'])
        ->assertOk();

    expect(Hash::check('new-password-2', $member->refresh()->password))->toBeTrue()
        ->and($member->tokens()->count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', ['action' => 'password changed', 'subject_id' => $member->id]);
});

it('stops people changing their own role or password from the staff screen', function (array $change, string $message) {
    $admin = signInStaffManager(attributes: ['password' => 'old-password-1']);

    $this->putJson("/api/v1/admin/staff/{$admin->id}", ['title' => $admin->name, 'subtitle' => $admin->email, 'owner' => 'admin', ...$change])
        ->assertUnprocessable()
        ->assertJsonPath('message', $message);

    expect($admin->refresh()->getRoleNames()->all())->toBe(['admin'])
        ->and(Hash::check('old-password-1', $admin->password))->toBeTrue();
})->with([
    'role' => [['owner' => 'user'], 'You cannot change your own role. Ask another administrator.'],
    'password' => [['password' => 'new-password-2', 'password_confirmation' => 'new-password-2'], 'Change your own password from Password Management.'],
]);

it('still lets people rename themselves', function () {
    $admin = signInStaffManager();

    $this->putJson("/api/v1/admin/staff/{$admin->id}", ['title' => 'My New Name', 'subtitle' => $admin->email, 'owner' => 'Admin'])->assertOk();

    expect($admin->refresh()->name)->toBe('My New Name');
});

it('shows what was really done to a staff account, newest first', function () {
    signInStaffManager(attributes: ['name' => 'Boss']);

    $this->travelTo(now()->subHours(2));
    $id = $this->postJson('/api/v1/admin/staff', newStaffForm())->json('data.id');
    $this->travelBack();
    $this->putJson("/api/v1/admin/staff/{$id}", ['title' => 'Nadia R.', 'subtitle' => 'nadia@example.com', 'owner' => 'admin'])->assertOk();
    // Another account's entry must not appear.
    ActivityLog::record(null, 'updated', 'staff account', 'Nadia R.', 999999);

    $history = $this->getJson("/api/v1/admin/staff/{$id}")->assertOk()->json('data.history');

    expect($history)->toBe([
        ['title' => 'Updated', 'meta' => 'Boss · 0 seconds ago'],
        ['title' => 'Created', 'meta' => 'Boss · 2 hours ago'],
    ]);
})->freezeTime();

it('matches entries saved without a record id by name, only since the record exists', function () {
    signInStaffManager();
    $member = staffAccount(attributes: ['name' => 'Omar', 'created_at' => '2026-05-10 08:00:00']);

    $this->travelTo('2026-05-01 08:00:00');
    ActivityLog::record(null, 'deleted', 'staff account', 'Omar');
    $this->travelTo('2026-05-12 08:00:00');
    ActivityLog::record(null, 'updated', 'staff account', 'Omar');
    ActivityLog::record(null, 'updated', 'author', 'Omar');
    $this->travelBack();

    $history = $this->getJson("/api/v1/admin/staff/{$member->id}")->assertOk()->json('data.history');

    expect(collect($history)->pluck('title')->all())->toBe(['Updated', 'Created'])
        ->and($history[1]['meta'])->toBe('10 May 2026');
});

it('gives the other record screens their own history', function () {
    signInStaffManager(attributes: ['name' => 'Boss']);

    $id = $this->postJson('/api/v1/admin/categories', ['title' => 'Domestic', 'status' => 'Active'])->assertCreated()->json('data.id');
    $other = Category::factory()->create();
    ActivityLog::record(null, 'updated', 'category', 'Something else', $other->id);

    $history = $this->getJson("/api/v1/admin/categories/{$id}")->assertOk()->json('data.history');

    expect($history)->toHaveCount(1)
        ->and($history[0]['title'])->toBe('Created')
        ->and($history[0]['meta'])->toStartWith('Boss · ');
});
