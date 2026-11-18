<?php

use App\Enums\RoleEnum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('issues an api token for valid credentials', function () {
    $user = User::factory()->create(['email' => 'admin@example.com', 'password' => 'secret-password']);
    $user->assignRole(RoleEnum::ADMIN->value);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'secret-password',
        'device_name' => 'nextjs',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'token',
            'token_type',
            'expires_at',
            'user' => ['id', 'name', 'email', 'avatar', 'roles', 'permissions', 'is_super_admin'],
        ])
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.roles.0', 'admin')
        ->assertJsonPath('user.is_super_admin', false);

    expect($user->tokens()->count())->toBe(1);
});

it('rejects invalid credentials', function () {
    User::factory()->create(['email' => 'admin@example.com', 'password' => 'secret-password']);

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('requires email and password', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rate limits repeated failed logins', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x']);
    }

    $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])
        ->assertTooManyRequests();
});

it('returns the authenticated user with roles and permissions', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleEnum::ADMIN->value);
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.roles.0', 'admin')
        ->assertJsonFragment(['users.index']);
});

it('flags super admins', function () {
    $user = User::factory()->create();
    $user->assignRole(RoleEnum::SUPER_ADMIN->value);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.is_super_admin', true);
});

it('blocks unauthenticated access with a json 401', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('revokes the token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});
