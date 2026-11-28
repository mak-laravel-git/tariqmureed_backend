<?php

namespace App\Support;

use App\Enums\RoleEnum;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Who may hand out or change which role. Besides the built-in roles, the dashboard can create its own, so nobody
 * may give, change or take over access that goes beyond their own. A super admin is not limited.
 */
final class RoleAccess
{
    /** Whether the role was made in the dashboard (the built-in ones cannot be deleted). */
    public static function isCustom(string $name): bool
    {
        return RoleEnum::tryFrom($name) === null;
    }

    public static function find(string $name): ?Role
    {
        return Role::query()->where('name', $name)->where('guard_name', RoleEnum::ADMIN->guard())->first();
    }

    /**
     * Whether the user has every one of these permissions.
     *
     * @param  iterable<int, string>  $permissions
     */
    public static function holds(User $user, iterable $permissions): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $own = $user->getAllPermissions()->pluck('name');

        return collect($permissions)->every(fn (string $name): bool => $own->contains($name));
    }

    /** Whether the user may give this role to someone, or change or delete the role itself. */
    public static function covers(User $user, Role $role): bool
    {
        if ($role->name === RoleEnum::SUPER_ADMIN->value) {
            return $user->isSuperAdmin();
        }

        return self::holds($user, $role->permissions->pluck('name'));
    }
}
