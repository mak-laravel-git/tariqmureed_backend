<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    private const SUBJECT = 'role';

    /**
     * The roles, and which module actions the selected role may use (the admin "Roles & Permissions" screen).
     */
    public function permissions(Request $request): JsonResponse
    {
        $roles = $this->roles();
        $role = $roles->firstWhere('name', (string) $request->query('role'))
            ?? $roles->firstWhere('name', RoleEnum::ADMIN->value);
        $selected = $role->name ?? RoleEnum::ADMIN->value;
        $granted = $role?->permissions->pluck('name')->all() ?? [];
        $isSuperAdmin = $selected === RoleEnum::SUPER_ADMIN->value;

        $modules = collect($this->modules())->map(fn (array $actions, string $module): array => [
            'key' => $module,
            'label' => Str::headline($module),
            'restricted' => array_keys($actions) === ['view'],
            'granted' => collect($actions)->map(
                fn (array $permissions): bool => $isSuperAdmin || collect($permissions)->every(fn (string $name): bool => in_array($name, $granted, true)),
            )->all(),
        ])->values();

        return response()->json(['data' => [
            'roles' => $roles->map(fn (Role $entry): array => [
                'slug' => $entry->name,
                'name' => Str::headline($entry->name),
                'description' => $this->describe($entry->name),
                'system' => $entry->name === RoleEnum::SUPER_ADMIN->value,
                'custom' => RoleAccess::isCustom($entry->name),
            ])->values(),
            'selected' => $selected,
            'actions' => $this->actions(),
            'modules' => $modules,
            'updatedAt' => 'Last updated '.($role?->updated_at?->diffForHumans() ?? 'never'),
            'can' => [
                'create' => $request->user()->can(PermissionEnum::ROLES_CREATE->value),
                'delete' => $request->user()->can(PermissionEnum::ROLES_DELETE->value),
            ],
        ]]);
    }

    /**
     * A new role. It starts with the dashboard only, so its staff can sign in; the rest is ticked afterwards.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(
            ['name' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z][A-Za-z0-9 -]*$/']],
            [
                'name.required' => 'Enter a name for the role.',
                'name.regex' => 'Use English letters, numbers, spaces and hyphens, starting with a letter.',
            ],
        );

        $slug = Str::slug((string) $request->input('name'));

        if (Role::query()->where('name', $slug)->exists() || ! RoleAccess::isCustom($slug)) {
            throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
        }

        $role = Role::create(['name' => $slug, 'guard_name' => RoleEnum::ADMIN->guard()]);

        if (RoleAccess::holds($request->user(), [PermissionEnum::DASHBOARD_VIEW->value])) {
            $role->givePermissionTo(PermissionEnum::DASHBOARD_VIEW->value);
        }

        ActivityLog::record($request->user(), 'created', self::SUBJECT, Str::headline($slug), $role->id);

        return response()->json(['data' => ['slug' => $slug, 'name' => Str::headline($slug)]], 201);
    }

    /**
     * Replace the permissions of a role with the ticked module actions.
     */
    public function update(Request $request, string $role): JsonResponse
    {
        $model = RoleAccess::find($role);

        abort_if($model === null, 404);

        if ($model->name === RoleEnum::SUPER_ADMIN->value) {
            return response()->json(['message' => 'The super admin role always has every permission.'], 422);
        }

        $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['array'], 'permissions.*.*' => ['string']]);

        $modules = $this->modules();
        $names = collect($request->input('permissions', []))
            ->flatMap(fn (array $actions, string $module): array => collect($actions)
                ->flatMap(fn (string $action): array => $modules[$module][$action] ?? [])
                ->all())
            ->unique()
            ->values()
            ->all();

        abort_unless(RoleAccess::covers($request->user(), $model), 403, 'This role has permissions you do not have, so you cannot change it.');
        abort_unless(RoleAccess::holds($request->user(), $names), 403, 'You can only give permissions that you have yourself.');

        $model->syncPermissions($names);
        $model->touch();

        ActivityLog::record($request->user(), 'updated', self::SUBJECT, Str::headline($model->name), $model->id);

        return response()->json(['message' => 'Permissions saved.']);
    }

    /**
     * Delete a role made in the dashboard that no staff account uses.
     */
    public function destroy(Request $request, string $role): JsonResponse
    {
        $model = RoleAccess::find($role);

        abort_if($model === null, 404);

        if (! RoleAccess::isCustom($model->name)) {
            return response()->json(['message' => 'The built-in roles cannot be deleted.'], 422);
        }

        abort_unless(RoleAccess::covers($request->user(), $model), 403, 'This role has permissions you do not have, so you cannot delete it.');

        $members = User::role($model->name)->count();

        if ($members > 0) {
            return response()->json([
                'message' => $members.' staff '.Str::plural('account', $members).' still '.($members === 1 ? 'uses' : 'use').' this role. Give them another role first.',
            ], 422);
        }

        ActivityLog::record($request->user(), 'deleted', self::SUBJECT, Str::headline($model->name), $model->id);
        $model->delete();

        return response()->json(null, 204);
    }

    /**
     * The built-in roles first, then those made in the dashboard by name.
     *
     * @return Collection<int, Role>
     */
    private function roles(): Collection
    {
        $order = array_column(RoleEnum::cases(), 'value');

        return Role::query()
            ->with('permissions')
            ->where('guard_name', RoleEnum::ADMIN->guard())
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Role $role): int => ($position = array_search($role->name, $order, true)) === false ? count($order) : $position)
            ->values()
            ->toBase();
    }

    private function describe(string $name): string
    {
        return match (RoleEnum::tryFrom($name)) {
            RoleEnum::SUPER_ADMIN => 'Full, permanent platform access',
            RoleEnum::ADMIN => 'Runs the dashboard with the permissions ticked below',
            RoleEnum::USER => 'Signed-in visitor with no admin access',
            default => ($members = User::role($name)->count()).' staff '.Str::plural('account', $members),
        };
    }

    /**
     * Permissions grouped as module => action label => permission names. "index" and "view" share the "view" action.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    private function modules(): array
    {
        $modules = [];

        foreach (PermissionEnum::cases() as $permission) {
            [$module, $action] = explode('.', $permission->value, 2);
            $label = $action === 'index' ? 'view' : $action;

            $modules[$module][$label][] = $permission->value;
        }

        return $modules;
    }

    /**
     * @return array<int, string>
     */
    private function actions(): array
    {
        return collect($this->modules())->flatMap(fn (array $actions): array => array_keys($actions))->unique()->values()->all();
    }
}
