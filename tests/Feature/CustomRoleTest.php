<?php

use App\Enums\RoleEnum;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function signInWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * @param  array<int, string>  $permissions
 */
function customRole(string $name, array $permissions = []): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => RoleEnum::ADMIN->guard()]);
    $role->syncPermissions($permissions);

    return $role;
}

/**
 * @return array<string, string>
 */
function staffForm(string $role, string $email = 'new.person@example.com'): array
{
    return ['title' => 'New Person', 'subtitle' => $email, 'owner' => $role, 'password' => 'first-password-1', 'password_confirmation' => 'first-password-1'];
}

it('creates a role that starts with the dashboard and shows it on the permissions screen', function () {
    signInWithRole('admin');

    $this->postJson('/api/v1/admin/roles', ['name' => 'Sports Editor'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'sports-editor')
        ->assertJsonPath('data.name', 'Sports Editor');

    $matrix = $this->getJson('/api/v1/admin/roles/permissions?role=sports-editor')->assertOk()->json('data');

    expect(array_column($matrix['roles'], 'slug'))->toBe(['super-admin', 'admin', 'user', 'sports-editor'])
        ->and($matrix['roles'][3])->toMatchArray(['custom' => true, 'system' => false, 'description' => '0 staff accounts'])
        ->and($matrix['roles'][1]['custom'])->toBeFalse()
        ->and($matrix['selected'])->toBe('sports-editor')
        ->and($matrix['can'])->toBe(['create' => true, 'delete' => true])
        ->and(Role::findByName('sports-editor', 'web')->permissions->pluck('name')->all())->toBe(['dashboard.view'])
        ->and(ActivityLog::query()->latest('id')->first()->only(['action', 'subject', 'target']))->toBe(['action' => 'created', 'subject' => 'role', 'target' => 'Sports Editor']);
});

it('refuses role names that are empty, unusable or taken', function (string $name) {
    signInWithRole('admin');
    customRole('reporter');

    $this->postJson('/api/v1/admin/roles', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
})->with(['', '123 desk', 'সম্পাদক', 'Reporter', 'Admin', 'Super Admin']);

it('gives a staff account the new role with exactly its permissions', function () {
    signInWithRole('admin');
    $this->postJson('/api/v1/admin/roles', ['name' => 'Reporter'])->assertCreated();
    $this->putJson('/api/v1/admin/roles/reporter/permissions', ['permissions' => ['dashboard' => ['view'], 'articles' => ['view', 'create']]])->assertOk();

    expect(array_column($this->getJson('/api/v1/admin/staff/roles')->assertOk()->json('data'), 'value'))->toBe(['admin', 'user', 'reporter']);

    $this->postJson('/api/v1/admin/staff', staffForm('reporter'))->assertCreated()->assertJsonPath('data.owner', 'Reporter');

    $reporter = User::query()->where('email', 'new.person@example.com')->sole();
    Sanctum::actingAs($reporter);

    $this->getJson('/api/v1/admin/articles')->assertOk();
    $this->getJson('/api/v1/admin/staff')->assertForbidden();
    $this->getJson('/api/v1/admin/settings')->assertForbidden();
    $this->postJson('/api/v1/admin/roles', ['name' => 'Another'])->assertForbidden();
});

it('refuses a role that does not exist', function () {
    signInWithRole('admin');

    $this->postJson('/api/v1/admin/staff', staffForm('ghost'))->assertUnprocessable()->assertJsonValidationErrors('owner');
});

it('deletes only an unused role made in the dashboard', function () {
    signInWithRole('admin');
    customRole('reporter');
    $member = User::factory()->create();
    $member->assignRole('reporter');

    $this->deleteJson('/api/v1/admin/roles/reporter')->assertUnprocessable()->assertJsonPath('message', '1 staff account still uses this role. Give them another role first.');

    foreach (['super-admin', 'admin', 'user'] as $builtIn) {
        $this->deleteJson("/api/v1/admin/roles/{$builtIn}")->assertUnprocessable();
    }

    $member->syncRoles(['user']);

    $this->deleteJson('/api/v1/admin/roles/reporter')->assertNoContent();
    $this->deleteJson('/api/v1/admin/roles/reporter')->assertNotFound();
});

it('never lets a limited role hand out or take over more access than it has', function () {
    $manager = customRole('staff-manager', ['dashboard.view', 'users.index', 'users.view', 'users.create', 'users.edit', 'users.delete', 'roles.index', 'roles.view', 'roles.create', 'roles.edit', 'roles.delete']);
    customRole('editor', ['articles.index']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    signInWithRole('staff-manager');

    // The staff form offers only what the account may give.
    expect(array_column($this->getJson('/api/v1/admin/staff/roles')->json('data'), 'value'))->toBe(['user', 'staff-manager']);

    $this->postJson('/api/v1/admin/staff', staffForm('admin'))->assertForbidden();
    $this->postJson('/api/v1/admin/staff', staffForm('editor', 'second@example.com'))->assertForbidden();
    $this->putJson("/api/v1/admin/staff/{$admin->id}", ['title' => 'Taken', 'subtitle' => $admin->email, 'owner' => 'user', 'password' => 'stolen-password-1', 'password_confirmation' => 'stolen-password-1'])->assertForbidden();
    $this->deleteJson("/api/v1/admin/staff/{$admin->id}")->assertForbidden();

    // It cannot add to its own role, change a bigger one, or delete it.
    $this->putJson('/api/v1/admin/roles/staff-manager/permissions', ['permissions' => ['settings' => ['view', 'edit']]])->assertForbidden();
    $this->putJson('/api/v1/admin/roles/admin/permissions', ['permissions' => []])->assertForbidden();
    $this->deleteJson('/api/v1/admin/roles/editor')->assertForbidden();

    $this->postJson('/api/v1/admin/staff', staffForm('user', 'third@example.com'))->assertCreated();

    expect($manager->refresh()->permissions)->toHaveCount(11);
});
