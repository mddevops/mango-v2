<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\RoleRequest;
use App\Models\Role;
use App\Services\AccessScope;
use App\Services\RoleManager;
use App\Support\PermissionCatalog;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly RoleManager $roles,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $user = $request->user();

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->withCount(['permissions', 'users'])
            ->orderByDesc('is_system')
            ->orderBy('label')
            ->get();

        return Inertia::render('roles/index', [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->displayName(),
                'is_system' => $role->is_system,
                'permissions_count' => $role->permissions_count,
                'users_count' => $role->users_count,
                'can' => [
                    'update' => $user->can('update', $role),
                    'delete' => $user->can('delete', $role) && $role->users_count === 0,
                ],
            ])->values(),
            'permissionsTotal' => count(PermissionCatalog::all()),
            'can' => ['create' => $user->can('create', Role::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('roles/form', [
            'role' => null,
            'groups' => PermissionCatalog::groups(),
            'ownPermissions' => $this->scope->permissionNames($request->user()),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);

        $role = $this->roles->create($request->user(), $request->createData());

        TableQuery::flashSuccess("Роль «{$role->displayName()}» создана.");

        return to_route('roles.edit', $role);
    }

    public function edit(Request $request, Role $role): Response
    {
        $this->authorize('update', $role);

        $role->load('permissions');

        return Inertia::render('roles/form', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->displayName(),
                'is_system' => $role->is_system,
                'is_administrator' => $role->isAdministrator(),
                'lock_version' => $role->lock_version,
                'permissions' => $role->permissions->pluck('name')->values(),
                'users_count' => $role->users()->count(),
            ],
            'groups' => PermissionCatalog::groups(),
            'ownPermissions' => $this->scope->permissionNames($request->user()),
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);

        $role = $this->roles->update($request->user(), $role, $request->updateData());

        TableQuery::flashSuccess("Роль «{$role->displayName()}» сохранена.");

        return to_route('roles.edit', $role);
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);

        $label = $role->displayName();
        $this->roles->delete($request->user(), $role);

        TableQuery::flashSuccess("Роль «{$label}» удалена.");

        return to_route('roles.index');
    }
}
