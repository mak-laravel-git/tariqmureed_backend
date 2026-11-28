<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreStaffRequest;
use App\Http\Requests\Api\V1\Admin\UpdateStaffRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\RoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class StaffController extends Controller
{
    private const SUBJECT = 'staff account';

    /**
     * Staff accounts as rows of the shared admin table (name, email, role).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 10), 1), 100);
        $search = trim((string) $request->query('q'));

        // Accounts have no draft or archived state, so only the "all" and "active" tabs show rows.
        $query = in_array($request->query('tab', 'all'), ['all', 'active'], true)
            ? User::query()
            : User::query()->whereRaw('1 = 0');

        $users = $query
            ->with('roles')
            ->when($search !== '', fn ($builder) => $builder->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user): array => $this->record($user))->values(),
            'meta' => ['total' => $users->total()],
        ]);
    }

    /**
     * The roles the signed-in account may give to someone: the choices of the staff form.
     */
    public function roles(Request $request): JsonResponse
    {
        $order = array_column(RoleEnum::cases(), 'value');

        $roles = Role::query()
            ->with('permissions')
            ->where('guard_name', RoleEnum::ADMIN->guard())
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role): bool => RoleAccess::covers($request->user(), $role))
            ->sortBy(fn (Role $role): int => ($position = array_search($role->name, $order, true)) === false ? count($order) : $position)
            ->map(fn (Role $role): array => ['value' => $role->name, 'label' => Str::headline($role->name)])
            ->values();

        return response()->json(['data' => $roles]);
    }

    public function show(string $id): JsonResponse
    {
        $user = User::query()->with('roles')->findOrFail($id);

        return response()->json(['data' => [
            ...$this->record($user),
            'history' => ActivityLog::historyOf(self::SUBJECT, $user, $user->name),
        ]]);
    }

    /**
     * Create an account with the password the administrator chose for it.
     */
    public function store(StoreStaffRequest $request): JsonResponse
    {
        $role = $request->validated('owner');

        $this->ensureCanGrant($request, $role);

        $user = DB::transaction(function () use ($request, $role): User {
            $user = User::query()->create([
                'name' => $request->validated('title'),
                'email' => $request->validated('subtitle'),
                'password' => $request->validated('password'),
            ]);
            $user->assignRole($role);

            return $user;
        });

        ActivityLog::record($request->user(), 'created', self::SUBJECT, $user->name, $user->id);

        return response()->json(['data' => $this->record($user->load('roles'))], 201);
    }

    /**
     * Rename an account, change its email address, move it to another role or give it a new password.
     */
    public function update(UpdateStaffRequest $request, string $id): JsonResponse
    {
        $user = User::query()->with('roles')->findOrFail($id);
        $role = $request->validated('owner');
        $password = $request->validated('password');

        $this->ensureCanManage($request, $user);
        $this->ensureCanGrant($request, $role);

        if ($user->isSuperAdmin() && $role !== RoleEnum::SUPER_ADMIN->value && $this->isLastSuperAdmin($user)) {
            return response()->json(['message' => 'The last super admin cannot be moved to another role.'], 422);
        }

        if ($user->is($request->user())) {
            // Either would be an easy way to lock yourself out.
            abort_if(! $user->hasRole($role), 422, 'You cannot change your own role. Ask another administrator.');
            abort_if($password !== null, 422, 'Change your own password from Password Management.');
        }

        $user->update([
            'name' => $request->validated('title'),
            'email' => $request->validated('subtitle'),
            ...($password !== null ? ['password' => $password] : []),
        ]);
        $user->syncRoles([$role]);
        ActivityLog::record($request->user(), 'updated', self::SUBJECT, $user->name, $user->id);

        if ($password !== null) {
            // Whoever was signed in with the old password has to sign in again.
            $user->tokens()->delete();
            ActivityLog::record($request->user(), 'password changed', self::SUBJECT, $user->name, $user->id);
        }

        return response()->json(['data' => $this->record($user->refresh()->load('roles'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        $this->ensureCanManage($request, $user);

        if ($user->is($request->user())) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($user->isSuperAdmin() && $this->isLastSuperAdmin($user)) {
            return response()->json(['message' => 'The last super admin cannot be deleted.'], 422);
        }

        ActivityLog::record($request->user(), 'deleted', self::SUBJECT, $user->name, $user->id);
        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }

    /**
     * Only a super admin may change another super admin, and nobody may change an account with more access than
     * their own (a new password would hand that access over).
     */
    private function ensureCanManage(Request $request, User $target): void
    {
        abort_if($target->isSuperAdmin() && ! $request->user()->isSuperAdmin(), 403, 'Only a super admin can change a super admin.');
        abort_unless(
            RoleAccess::holds($request->user(), $target->getAllPermissions()->pluck('name')),
            403,
            'This account has permissions you do not have, so you cannot change it.',
        );
    }

    private function ensureCanGrant(Request $request, string $role): void
    {
        abort_if($role === RoleEnum::SUPER_ADMIN->value && ! $request->user()->isSuperAdmin(), 403, 'Only a super admin can grant the super admin role.');

        $model = RoleAccess::find($role);

        abort_if($model !== null && ! RoleAccess::covers($request->user(), $model), 403, 'You can only give a role whose permissions you have yourself.');
    }

    private function isLastSuperAdmin(User $user): bool
    {
        return User::role(RoleEnum::SUPER_ADMIN->value)->whereKeyNot($user->getKey())->doesntExist();
    }

    /**
     * @return array{id: string, title: string, subtitle: string, owner: string, role: string, status: string, updatedAt: string, notes: null}
     */
    private function record(User $user): array
    {
        $role = (string) $user->roles->first()?->name;

        return [
            'id' => (string) $user->id,
            'title' => $user->name,
            'subtitle' => $user->email,
            'owner' => Str::headline($role),
            'role' => $role,
            'status' => 'Active',
            'updatedAt' => $user->updated_at->diffForHumans(),
            'notes' => null,
        ];
    }
}
