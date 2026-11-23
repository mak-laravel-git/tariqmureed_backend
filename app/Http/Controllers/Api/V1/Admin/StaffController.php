<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateStaffRequest;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffController extends Controller
{
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

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->record(User::query()->with('roles')->findOrFail($id))]);
    }

    /**
     * Rename an account, change its email address or move it to another role.
     */
    public function update(UpdateStaffRequest $request, string $id): JsonResponse
    {
        $user = User::query()->with('roles')->findOrFail($id);
        $role = $request->validated('owner');

        $this->ensureCanManage($request, $user);

        if ($role === RoleEnum::SUPER_ADMIN->value && ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can grant the super admin role.'], 403);
        }

        if ($user->isSuperAdmin() && $role !== RoleEnum::SUPER_ADMIN->value && $this->isLastSuperAdmin($user)) {
            return response()->json(['message' => 'The last super admin cannot be moved to another role.'], 422);
        }

        $user->update(['name' => $request->validated('title'), 'email' => $request->validated('subtitle')]);
        $user->syncRoles([$role]);
        ActivityLog::record($request->user(), 'updated', 'staff account', $user->name);

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

        ActivityLog::record($request->user(), 'deleted', 'staff account', $user->name);
        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }

    /**
     * Only a super admin may change another super admin.
     */
    private function ensureCanManage(Request $request, User $target): void
    {
        abort_if($target->isSuperAdmin() && ! $request->user()->isSuperAdmin(), 403, 'Only a super admin can change a super admin.');
    }

    private function isLastSuperAdmin(User $user): bool
    {
        return User::role(RoleEnum::SUPER_ADMIN->value)->whereKeyNot($user->getKey())->doesntExist();
    }

    /**
     * @return array{id: string, title: string, subtitle: string, owner: string, status: string, updatedAt: string, notes: null}
     */
    private function record(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'title' => $user->name,
            'subtitle' => $user->email,
            'owner' => Str::headline((string) $user->roles->first()?->name),
            'status' => 'Active',
            'updatedAt' => $user->updated_at->diffForHumans(),
            'notes' => null,
        ];
    }
}
