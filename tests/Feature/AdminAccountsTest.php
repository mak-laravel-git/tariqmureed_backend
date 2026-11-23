<?php

use App\Enums\RoleEnum;
use App\Models\League;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function signInAs(RoleEnum $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    Sanctum::actingAs($user);

    return $user;
}

it('lets an admin change their own name and email', function () {
    $admin = signInAs(RoleEnum::ADMIN);

    $this->putJson('/api/v1/admin/profile', ['name' => 'New Name', 'email' => 'new@example.com'])->assertOk();

    expect($admin->refresh())->name->toBe('New Name')->email->toBe('new@example.com');
});

it('rejects a profile email that another account already uses', function () {
    $other = User::factory()->create();
    signInAs(RoleEnum::ADMIN);

    $this->putJson('/api/v1/admin/profile', ['name' => 'Admin', 'email' => $other->email])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('changes the password only with the right current password', function () {
    $admin = signInAs(RoleEnum::ADMIN);
    $admin->update(['password' => 'old-password-123']);

    $this->putJson('/api/v1/admin/profile/password', [
        'current_password' => 'wrong',
        'password' => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $this->putJson('/api/v1/admin/profile/password', [
        'current_password' => 'old-password-123',
        'password' => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ])->assertOk();

    expect(Hash::check('new-password-456', $admin->refresh()->password))->toBeTrue();
});

it('lists and searches staff for an admin only', function () {
    User::factory()->create(['name' => 'Zara Editor', 'email' => 'zara@example.com']);

    signInAs(RoleEnum::USER);
    $this->getJson('/api/v1/admin/staff')->assertForbidden();

    signInAs(RoleEnum::ADMIN);

    $this->getJson('/api/v1/admin/staff?q=zara')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.subtitle', 'zara@example.com');
});

it('changes a staff role and keeps the last super admin safe', function () {
    $superAdmin = signInAs(RoleEnum::SUPER_ADMIN);
    $member = User::factory()->create();
    $member->assignRole(RoleEnum::USER->value);

    $this->putJson("/api/v1/admin/staff/{$member->id}", ['title' => $member->name, 'subtitle' => $member->email, 'owner' => 'Admin'])->assertOk();
    expect($member->refresh()->hasRole('admin'))->toBeTrue();

    $this->putJson("/api/v1/admin/staff/{$superAdmin->id}", ['title' => $superAdmin->name, 'subtitle' => $superAdmin->email, 'owner' => 'admin'])
        ->assertUnprocessable();
    $this->deleteJson("/api/v1/admin/staff/{$superAdmin->id}")->assertUnprocessable();
});

it('stops an admin from granting or touching the super admin role', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(RoleEnum::SUPER_ADMIN->value);
    $member = User::factory()->create();
    $member->assignRole(RoleEnum::USER->value);

    signInAs(RoleEnum::ADMIN);

    $this->putJson("/api/v1/admin/staff/{$member->id}", ['title' => 'X', 'subtitle' => $member->email, 'owner' => 'super-admin'])->assertForbidden();
    $this->deleteJson("/api/v1/admin/staff/{$superAdmin->id}")->assertForbidden();
});

it('deletes a staff account but never your own', function () {
    $admin = signInAs(RoleEnum::ADMIN);
    $member = User::factory()->create();

    $this->deleteJson("/api/v1/admin/staff/{$member->id}")->assertNoContent();
    $this->assertModelMissing($member);
    $this->deleteJson("/api/v1/admin/staff/{$admin->id}")->assertUnprocessable();
});

it('shows the permission matrix and saves the ticked actions', function () {
    signInAs(RoleEnum::SUPER_ADMIN);

    $matrix = $this->getJson('/api/v1/admin/roles/permissions?role=admin')->assertOk()->json('data');

    expect($matrix['selected'])->toBe('admin')
        ->and(collect($matrix['modules'])->firstWhere('key', 'settings')['granted'])->toMatchArray(['view' => true, 'edit' => true]);

    $this->putJson('/api/v1/admin/roles/admin/permissions', ['permissions' => ['settings' => ['view'], 'pages' => ['view', 'edit']]])->assertOk();

    expect(Role::findByName('admin', RoleEnum::ADMIN->guard())->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['pages.edit', 'pages.view', 'settings.view']);
});

it('keeps the super admin role locked and the matrix behind roles permissions', function () {
    signInAs(RoleEnum::SUPER_ADMIN);
    $this->putJson('/api/v1/admin/roles/super-admin/permissions', ['permissions' => []])->assertUnprocessable();
    $this->putJson('/api/v1/admin/roles/unknown/permissions', ['permissions' => []])->assertNotFound();

    signInAs(RoleEnum::USER);
    $this->getJson('/api/v1/admin/roles/permissions')->assertForbidden();
    $this->putJson('/api/v1/admin/roles/admin/permissions', ['permissions' => []])->assertForbidden();
});

it('lists synced competitions read-only', function () {
    League::factory()->create(['name' => 'Indian Premier League']);
    League::factory()->create(['name' => 'Big Bash League']);

    signInAs(RoleEnum::ADMIN);

    $this->getJson('/api/v1/admin/competitions?q=premier')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Indian Premier League');
});
