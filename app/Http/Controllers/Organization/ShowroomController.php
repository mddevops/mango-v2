<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\ShowroomRequest;
use App\Http\Requests\Organization\StatusRequest;
use App\Models\Showroom;
use App\Services\AccessScope;
use App\Services\OrganizationManager;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use App\Support\Timezones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowroomController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly OrganizationManager $organization,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Showroom::class);

        $user = $request->user();
        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['code', 'name', 'address', 'phone']),
            'status' => TableFilter::status(),
        ]);

        $paginator = $filters->apply($this->scope->showrooms(Showroom::query(), $user))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('showrooms/index', [
            'showrooms' => TableQuery::paginated($paginator, fn (Showroom $showroom) => [
                'id' => $showroom->id,
                'code' => $showroom->code,
                'name' => $showroom->name,
                'address' => $showroom->address,
                'phone' => $showroom->phone,
                'timezone' => $showroom->timezone,
                'business_day_boundary_enabled' => $showroom->business_day_boundary_enabled,
                'business_day_boundary' => $showroom->business_day_boundary,
                'active' => $showroom->active,
                'lock_version' => $showroom->lock_version,
                'can' => ['update' => $user->can('update', $showroom)],
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'timezones' => Timezones::options(),
            'can' => ['create' => $user->can('create', Showroom::class)],
        ]);
    }

    public function store(ShowroomRequest $request): RedirectResponse
    {
        $this->authorize('create', Showroom::class);

        $showroom = $this->organization->createShowroom($request->createData());

        TableQuery::flashSuccess("Салон «{$showroom->name}» создан.");

        return back();
    }

    public function update(ShowroomRequest $request, Showroom $showroom): RedirectResponse
    {
        $this->authorize('update', $showroom);

        $showroom = $this->organization->updateShowroom($showroom, $request->updateData());

        TableQuery::flashSuccess("Салон «{$showroom->name}» сохранён.");

        return back();
    }

    public function status(StatusRequest $request, Showroom $showroom): RedirectResponse
    {
        $this->authorize('update', $showroom);

        $showroom = $this->organization->setShowroomActive($showroom, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess($showroom->active
            ? "Салон «{$showroom->name}» активирован."
            : "Салон «{$showroom->name}» деактивирован.");

        return back();
    }
}
