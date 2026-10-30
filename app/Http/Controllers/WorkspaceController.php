<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Role;
use App\Models\Showroom;
use App\Services\AccessScope;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function __invoke(Request $request, AccessScope $scope): Response
    {
        $user = $request->user();

        abort_unless($user->can(PermissionCatalog::WORKSPACE_VIEW), 403);

        $global = $scope->isGlobal($user);

        $showrooms = $global
            ? Showroom::query()->where('active', true)->orderBy('name')->get()
            : $user->showrooms()->orderBy('name')->get();

        return Inertia::render('dashboard', [
            'employee' => [
                'name' => $user->name,
                'email' => $user->email,
                'globalAccess' => $global,
                'roles' => $user->roles->sortBy(fn (Role $role) => $role->displayName())
                    ->map(fn (Role $role) => ['id' => $role->id, 'label' => $role->displayName()])
                    ->values(),
                'showrooms' => $showrooms
                    ->map(fn (Showroom $showroom) => [
                        'id' => $showroom->id,
                        'name' => $showroom->name,
                        'address' => $showroom->address,
                        'active' => $showroom->active,
                    ])
                    ->values(),
                'departments' => $user->departments()->with('showroom')->orderBy('name')->get()
                    ->map(fn (Department $department) => [
                        'id' => $department->id,
                        'name' => $department->name,
                        'kind' => $department->kind->label(),
                        'showroom' => $department->showroom?->name,
                        'active' => $department->active,
                    ])
                    ->values(),
            ],
        ]);
    }
}
