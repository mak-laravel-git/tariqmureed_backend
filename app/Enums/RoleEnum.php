<?php

namespace App\Enums;

enum RoleEnum: string
{
    case SUPER_ADMIN = 'super-admin';
    case ADMIN = 'admin';
    case USER = 'user';

    /**
     * Guard name for this role. Must match PermissionEnum::guard() and config/permission.php.
     */
    public function guard(): string
    {
        return GuardEnum::WEB->value;
    }

    /**
     * Permissions granted to the role.
     *
     * super-admin returns [] on purpose: Gate::before grants it everything, so assigning
     * every permission would only bloat the pivot table.
     * Re-run `php artisan db:seed --class=RoleSeeder` after changing this list.
     *
     * @return array<int, PermissionEnum>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SUPER_ADMIN => [],
            self::ADMIN => PermissionEnum::cases(),
            self::USER => [],
        };
    }
}
