<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * The roles, and which module actions the selected role may use (the admin "Roles & Permissions" screen).
     */
    public function permissions(Request $request): JsonResponse
    {
        $selected = RoleEnum::tryFrom((string) $request->query('role')) ?? RoleEnum::ADMIN;
        $role = Role::query()->with('permissions')->where('name', $selected->value)->first();
        $granted = $role?->permissions->pluck('name')->all() ?? [];
        $isSuperAdmin = $selected === RoleEnum::SUPER_ADMIN;

        $modules = collect($this->modules())->map(fn (array $actions, string $module): array => [
            'key' => $module,
            'label' => Str::headline($module),
            'restricted' => array_keys($actions) === ['view'],
            'granted' => collect($actions)->map(
                fn (array $permissions): bool => $isSuperAdmin || collect($permissions)->every(fn (string $name): bool => in_array($name, $granted, true)),
            )->all(),
        ])->values();

        return response()->json(['data' => [
            'roles' => collect(RoleEnum::cases())->map(fn (RoleEnum $entry): array => [
                'slug' => $entry->value,
                'name' => Str::headline($entry->value),
                'description' => match ($entry) {
                    RoleEnum::SUPER_ADMIN => 'Full, permanent platform access',
                    RoleEnum::ADMIN => 'Runs the dashboard with the permissions ticked below',
                    RoleEnum::USER => 'Signed-in visitor with no admin access',
                },
                'system' => $entry === RoleEnum::SUPER_ADMIN,
            ])->all(),
            'selected' => $selected->value,
            'actions' => $this->actions(),
            'modules' => $modules,
            'updatedAt' => 'Last updated '.($role?->updated_at?->diffForHumans() ?? 'never'),
        ]]);
    }

    /**
     * Replace the permissions of a role with the ticked module actions.
     */
    public function update(Request $request, string $role): JsonResponse
    {
        $roleEnum = RoleEnum::tryFrom($role);

        abort_if($roleEnum === null, 404);

        if ($roleEnum === RoleEnum::SUPER_ADMIN) {
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

        $model = Role::findByName($roleEnum->value, $roleEnum->guard());
        $model->syncPermissions($names);
        $model->touch();

        return response()->json(['message' => 'Permissions saved.']);
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
