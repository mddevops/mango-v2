<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\SetUserPasswordRequest;
use App\Http\Requests\Organization\StatusRequest;
use App\Http\Requests\Organization\UserRequest;
use App\Models\Department;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\UserManager;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly UserManager $users,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $viewer = $request->user();
        $scopeIds = $this->scope->showroomIds($viewer);

        $showrooms = $this->scope->showrooms(Showroom::query(), $viewer)->orderBy('name')->get(['id', 'name', 'active']);
        $roles = Role::query()->where('guard_name', 'web')->orderBy('label')->get();

        // No «пусто» for salons: a user with access to all salons has no salon links.
        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['name', 'email']),
            'showroom' => TableFilter::relation('showrooms', 'showrooms.id', $showrooms->modelKeys(), emptiable: false),
            'role' => TableFilter::relation('roles', 'roles.id', $roles->modelKeys()),
            'status' => TableFilter::status(),
        ]);

        $paginator = $filters->apply($this->scope->users(User::query(), $viewer))
            ->with([
                'roles:id,name,label',
                'showrooms' => fn ($query) => $scopeIds === null ? $query : $query->whereIn('showrooms.id', $scopeIds),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('users/index', [
            'users' => TableQuery::paginated($paginator, fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->active,
                'has_global_access' => $user->has_global_access,
                'lock_version' => $user->lock_version,
                'is_self' => $viewer->is($user),
                'roles' => $user->roles->map(fn (Role $role) => $role->displayName())->values(),
                'showrooms' => $user->showrooms->pluck('name')->values(),
                'can' => [
                    'update' => $viewer->can('update', $user),
                    'changeStatus' => $viewer->can('changeStatus', $user),
                ],
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'showrooms' => $showrooms->map(fn (Showroom $showroom) => ['id' => $showroom->id, 'name' => $showroom->name])->values(),
            'roles' => $roles->map(fn (Role $role) => ['id' => $role->id, 'label' => $role->displayName()])->values(),
            'can' => ['create' => $viewer->can('create', User::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('users/form', [
            'user' => null,
            'options' => $this->options($request->user(), null),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'can' => [
                'setPassword' => false,
                'changeStatus' => false,
                'grantGlobalAccess' => false,
            ],
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $user = $this->users->create($request->user(), $request->createData());

        TableQuery::flashSuccess("Сотрудник «{$user->name}» создан.");

        return to_route('users.edit', $user);
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('view', $user);

        $editor = $request->user();
        $scopeIds = $this->scope->showroomIds($editor);
        $global = $scopeIds === null;

        $showrooms = $user->showrooms()->get();
        $departments = $user->departments()->get();

        $visibleShowrooms = $global ? $showrooms : $showrooms->filter(fn (Showroom $s) => in_array($s->id, $scopeIds, true));
        $managedDepartments = $global ? $departments : $departments->filter(fn (Department $d) => ! $d->isShared() && in_array($d->showroom_id, $scopeIds, true));

        return Inertia::render('users/form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'active' => $user->active,
                'has_global_access' => $user->has_global_access,
                'lock_version' => $user->lock_version,
                'is_self' => $editor->is($user),
                'role_ids' => $user->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->values(),
                'showroom_ids' => $visibleShowrooms->modelKeys(),
                'department_ids' => $managedDepartments->modelKeys(),
                'hidden_assignments' => ($showrooms->count() - $visibleShowrooms->count()) + ($departments->count() - $managedDepartments->count()),
            ],
            'options' => $this->options($editor, $user),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'can' => [
                'update' => $editor->can('update', $user),
                'setPassword' => $editor->can('setPassword', $user),
                'changeStatus' => $editor->can('changeStatus', $user),
                'grantGlobalAccess' => $editor->can('grantGlobalAccess', $user),
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $user = $this->users->update($request->user(), $user, $request->updateData());

        TableQuery::flashSuccess("Сотрудник «{$user->name}» сохранён.");

        return to_route('users.edit', $user);
    }

    public function status(StatusRequest $request, User $user): RedirectResponse
    {
        $this->authorize('changeStatus', $user);

        $user = $this->users->setActive($request->user(), $user, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess($user->active
            ? "Сотрудник «{$user->name}» активирован."
            : "Сотрудник «{$user->name}» отключён. Его открытые сессии завершены.");

        return back();
    }

    public function password(SetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->authorize('setPassword', $user);

        $this->users->setPassword($request->user(), $user, $request->validated('password'));

        TableQuery::flashSuccess("Новый пароль для «{$user->name}» установлен.");

        return back();
    }

    public function globalAccess(StatusRequest $request, User $user): RedirectResponse
    {
        $this->authorize('grantGlobalAccess', $user);

        $user = $this->users->setGlobalAccess($request->user(), $user, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess($user->has_global_access
            ? "Сотруднику «{$user->name}» выдан глобальный доступ."
            : "У сотрудника «{$user->name}» отозван глобальный доступ.");

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function options(User $editor, ?User $target): array
    {
        $global = $this->scope->isGlobal($editor);
        $assignedShowroomIds = $target ? $target->showrooms()->pluck('showrooms.id')->all() : [];
        $assignedDepartmentIds = $target ? $target->departments()->pluck('departments.id')->all() : [];

        $showrooms = $this->scope->showrooms(Showroom::query(), $editor)
            ->where(fn (Builder $query) => $query->where('active', true)->orWhereIn('id', $assignedShowroomIds))
            ->orderBy('name')
            ->get();

        $departments = Department::query()
            ->with('showroom:id,name')
            ->when(! $global, fn (Builder $query) => $query
                ->whereIn('showroom_id', $this->scope->showroomIds($editor) ?? []))
            ->where(fn (Builder $query) => $query->where('active', true)->orWhereIn('id', $assignedDepartmentIds))
            ->orderByRaw('showroom_id is not null')
            ->orderBy('name')
            ->get();

        $roles = Role::query()->with('permissions')->where('guard_name', 'web')->orderBy('label')->get();

        return [
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'label' => $role->displayName(),
                'delegable' => $this->scope->canDelegateRole($editor, $role),
            ])->values(),
            'showrooms' => $showrooms->map(fn (Showroom $showroom) => [
                'id' => $showroom->id,
                'name' => $showroom->name,
                'active' => $showroom->active,
            ])->values(),
            'departments' => $departments->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'kind_label' => $department->kind->label(),
                'showroom_id' => $department->showroom_id,
                'showroom' => $department->showroom?->name,
                'active' => $department->active,
            ])->values(),
            'globalEditor' => $global,
        ];
    }
}
