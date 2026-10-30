<?php

namespace App\Http\Controllers\Sources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StatusRequest;
use App\Http\Requests\Sources\IntegrationConnectionRequest;
use App\Models\IntegrationConnection;
use App\Models\Showroom;
use App\Services\IntegrationConnectionManager;
use App\Support\IntegrationProvider;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings of external accounts. The page never receives secret values, only flags
 * telling whether each secret is set.
 */
class IntegrationConnectionController extends Controller
{
    public function __construct(private readonly IntegrationConnectionManager $connections) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', IntegrationConnection::class);

        $provider = IntegrationProvider::MangoVpbx;
        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['name']),
            'status' => TableFilter::status(),
        ]);

        $paginator = $filters->apply(IntegrationConnection::query()->where('provider', $provider->value))
            ->with(['showrooms' => fn ($query) => $query->orderBy('name')])
            ->withCount('bindings')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('connections/index', [
            'connections' => TableQuery::paginated($paginator, fn (IntegrationConnection $connection) => [
                'id' => $connection->id,
                'provider' => $connection->provider->value,
                'provider_label' => $connection->provider->label(),
                'name' => $connection->name,
                'active' => $connection->active,
                'verification_status' => $connection->verification_status,
                'showrooms' => $connection->showrooms->map(fn (Showroom $showroom) => [
                    'id' => $showroom->id,
                    'name' => $showroom->name,
                    'active' => $showroom->active,
                ])->values(),
                'secrets' => $connection->secretPresence(),
                'sources_count' => (int) $connection->getAttribute('bindings_count'),
                'lock_version' => $connection->lock_version,
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'showrooms' => Showroom::query()->orderBy('name')->get(['id', 'name', 'active']),
            'secretFields' => collect($provider->secretFields())
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'can' => [
                'manageSecrets' => $request->user()->can('manageSecrets', IntegrationConnection::class),
            ],
        ]);
    }

    public function store(IntegrationConnectionRequest $request): RedirectResponse
    {
        $connection = $this->connections->create(IntegrationProvider::MangoVpbx, $request->connectionData());

        TableQuery::flashSuccess("Подключение «{$connection->name}» сохранено. Состояние: не проверено.");

        return back();
    }

    public function update(IntegrationConnectionRequest $request, IntegrationConnection $connection): RedirectResponse
    {
        $connection = $this->connections->update($connection, $request->updateData());

        TableQuery::flashSuccess("Подключение «{$connection->name}» сохранено. Состояние: не проверено.");

        return back();
    }

    public function status(StatusRequest $request, IntegrationConnection $connection): RedirectResponse
    {
        $connection = $this->connections->setActive($connection, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess($connection->active
            ? "Подключение «{$connection->name}» включено."
            : "Подключение «{$connection->name}» отключено.");

        return back();
    }
}
