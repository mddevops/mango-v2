<?php

namespace App\Http\Controllers\Organization;

use App\Enums\DepartmentKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\DepartmentRequest;
use App\Http\Requests\Organization\StatusRequest;
use App\Models\Department;
use App\Models\Showroom;
use App\Services\AccessScope;
use App\Services\OrganizationManager;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly OrganizationManager $organization,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Department::class);

        $user = $request->user();
        $showrooms = $this->scope->showrooms(Showroom::query(), $user)->orderBy('name')->get(['id', 'name', 'active']);

        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['code', 'name']),
            'showroom' => TableFilter::column('showroom_id', $showrooms->modelKeys(), nullable: true),
            'kind' => TableFilter::column('kind', array_map(fn (DepartmentKind $kind) => $kind->value, DepartmentKind::cases())),
            'status' => TableFilter::status(),
        ]);

        $paginator = $filters->apply($this->scope->departments(Department::query(), $user))
            ->with('showroom:id,name')
            ->orderByRaw('showroom_id is not null')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        $global = $this->scope->isGlobal($user);

        return Inertia::render('departments/index', [
            'departments' => TableQuery::paginated($paginator, fn (Department $department) => [
                'id' => $department->id,
                'code' => $department->code,
                'name' => $department->name,
                'kind' => $department->kind->value,
                'kind_label' => $department->kind->label(),
                'showroom' => $department->showroom ? ['id' => $department->showroom->id, 'name' => $department->showroom->name] : null,
                'active' => $department->active,
                'lock_version' => $department->lock_version,
                'can' => ['update' => $user->can('update', $department)],
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'showrooms' => $showrooms->map(fn (Showroom $showroom) => [
                'id' => $showroom->id,
                'name' => $showroom->name,
                'active' => $showroom->active,
            ])->values(),
            'kinds' => DepartmentKind::options(),
            'can' => [
                'create' => $user->can('create', Department::class),
                'createShared' => Gate::allows('createIn', [Department::class, null]),
            ],
            'globalAccess' => $global,
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        $data = $request->createData();

        Gate::authorize('createIn', [Department::class, $data['showroom_id']]);

        if ($data['showroom_id'] !== null && ! Showroom::query()->whereKey($data['showroom_id'])->where('active', true)->exists()) {
            throw ValidationException::withMessages(['showroom_id' => 'Салон отключён.']);
        }

        $department = $this->organization->createDepartment($data);

        TableQuery::flashSuccess("Отдел «{$department->name}» создан.");

        return back();
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $this->authorize('update', $department);

        $department = $this->organization->updateDepartment($department, $request->updateData());

        TableQuery::flashSuccess("Отдел «{$department->name}» сохранён.");

        return back();
    }

    public function status(StatusRequest $request, Department $department): RedirectResponse
    {
        $this->authorize('update', $department);

        $department = $this->organization->setDepartmentActive($department, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess($department->active
            ? "Отдел «{$department->name}» активирован."
            : "Отдел «{$department->name}» деактивирован.");

        return back();
    }
}
