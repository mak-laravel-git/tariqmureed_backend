<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Permissions the application understands, named "<resource>.<action>".
 *
 * Add a case here, run `php artisan db:seed --class=PermissionSeeder`, then attach it
 * to roles in RoleEnum::permissions().
 */
enum PermissionEnum: string
{
    // USERS
    case USERS_INDEX = 'users.index';
    case USERS_VIEW = 'users.view';
    case USERS_CREATE = 'users.create';
    case USERS_EDIT = 'users.edit';
    case USERS_DELETE = 'users.delete';

    // ROLES
    case ROLES_INDEX = 'roles.index';
    case ROLES_VIEW = 'roles.view';
    case ROLES_CREATE = 'roles.create';
    case ROLES_EDIT = 'roles.edit';
    case ROLES_DELETE = 'roles.delete';

    // PERMISSIONS (developer-defined, read-only)
    case PERMISSIONS_INDEX = 'permissions.index';

    // DASHBOARD (admin overview page)
    case DASHBOARD_VIEW = 'dashboard.view';

    // INTEGRATIONS (SportMonks status page and manual sync)
    case INTEGRATIONS_VIEW = 'integrations.view';
    case INTEGRATIONS_SYNC = 'integrations.sync';

    // SETTINGS (website settings: contact details, Google Analytics / AdSense IDs, consent mode)
    case SETTINGS_VIEW = 'settings.view';
    case SETTINGS_EDIT = 'settings.edit';

    // PAGES (privacy policy, cookie and terms pages)
    case PAGES_VIEW = 'pages.view';
    case PAGES_EDIT = 'pages.edit';

    /**
     * Display group stored in the permissions.group column ("Users", "Roles", ...).
     */
    public function group(): string
    {
        return Str::headline(Str::before($this->value, '.'));
    }

    public function guard(): string
    {
        return GuardEnum::WEB->value;
    }
}
