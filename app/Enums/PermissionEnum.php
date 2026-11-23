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

    // ARTICLES (stories shown on the public news pages)
    case ARTICLES_INDEX = 'articles.index';
    case ARTICLES_VIEW = 'articles.view';
    case ARTICLES_CREATE = 'articles.create';
    case ARTICLES_EDIT = 'articles.edit';
    case ARTICLES_DELETE = 'articles.delete';

    // CATEGORIES (news categories)
    case CATEGORIES_INDEX = 'categories.index';
    case CATEGORIES_VIEW = 'categories.view';
    case CATEGORIES_CREATE = 'categories.create';
    case CATEGORIES_EDIT = 'categories.edit';
    case CATEGORIES_DELETE = 'categories.delete';

    // WRITERS (authors credited on articles)
    case WRITERS_INDEX = 'writers.index';
    case WRITERS_VIEW = 'writers.view';
    case WRITERS_CREATE = 'writers.create';
    case WRITERS_EDIT = 'writers.edit';
    case WRITERS_DELETE = 'writers.delete';

    // ADVERTISEMENTS (advertising campaign register)
    case ADVERTISEMENTS_INDEX = 'advertisements.index';
    case ADVERTISEMENTS_VIEW = 'advertisements.view';
    case ADVERTISEMENTS_CREATE = 'advertisements.create';
    case ADVERTISEMENTS_EDIT = 'advertisements.edit';
    case ADVERTISEMENTS_DELETE = 'advertisements.delete';

    // MEDIA (image library)
    case MEDIA_INDEX = 'media.index';
    case MEDIA_CREATE = 'media.create';
    case MEDIA_DELETE = 'media.delete';

    // ACTIVITY (log of dashboard actions, read-only)
    case ACTIVITY_INDEX = 'activity.index';

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
