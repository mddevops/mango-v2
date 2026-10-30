<?php

namespace App\Http\Controllers\Sources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StatusRequest;
use App\Http\Requests\Sources\SourceRequest;
use App\Models\Agency;
use App\Models\ContactChannel;
use App\Models\IntegrationConnection;
use App\Models\IntegrationLine;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\SourceClassification;
use App\Models\SourceKind;
use App\Services\AccessScope;
use App\Services\SourceManager;
use App\Support\IntegrationProvider;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SourceController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly SourceManager $sources,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Source::class);

        $user = $request->user();
        $manageConnections = $user->can('viewAny', IntegrationConnection::class);

        $showrooms = $this->scope->showrooms(Showroom::query(), $user)->orderBy('name')->get(['id', 'name', 'active']);
        $kinds = SourceKind::query()->with(['classifications:id', 'channels:id'])->orderBy('sort_order')->orderBy('name')->get();
        $classifications = SourceClassification::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'active']);
        $channels = ContactChannel::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'active']);
        $agencies = Agency::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'active']);

        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['name', 'code', 'url']),
            'showroom' => TableFilter::column('showroom_id', $showrooms->modelKeys()),
            'kind' => TableFilter::column('source_kind_id', $kinds->modelKeys()),
            'classification' => TableFilter::column('source_classification_id', $classifications->modelKeys(), nullable: true),
            'channel' => TableFilter::relation('channels', 'contact_channels.id', $channels->modelKeys()),
            'agency' => TableFilter::column('agency_id', $agencies->modelKeys(), nullable: true),
            'status' => TableFilter::status(),
        ]);

        $paginator = $filters->apply($this->scope->sources(Source::query(), $user))
            ->with([
                'showroom:id,name,active',
                'kind:id,name,active',
                'classification:id,name,active',
                'legacyClassification:id,name',
                'agency:id,name,active',
                'channels' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'),
                'siteIntake',
                'connectionBindings' => fn ($query) => $query->where('provider', IntegrationProvider::MangoVpbx->value),
                'connectionBindings.connection:id,name,active',
                'connectionBindings.lines',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('sources/index', [
            'sources' => TableQuery::paginated($paginator, fn (Source $source) => $this->row($source, $user->can('update', $source), $manageConnections)),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'showrooms' => $this->options($showrooms),
            'kinds' => $kinds->map(fn (SourceKind $kind) => [
                'id' => $kind->id,
                'name' => $kind->name,
                'active' => $kind->active,
                'classification_ids' => $kind->classifications->modelKeys(),
                'channel_ids' => $kind->channels->modelKeys(),
            ])->values(),
            'classifications' => $this->options($classifications),
            'channels' => $this->options($channels),
            'agencies' => $this->options($agencies),
            'mangoConnections' => $manageConnections
                ? IntegrationConnection::query()
                    ->where('provider', IntegrationProvider::MangoVpbx->value)
                    ->with('showrooms:id')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (IntegrationConnection $connection) => [
                        'id' => $connection->id,
                        'name' => $connection->name,
                        'active' => $connection->active,
                        'showroom_ids' => $connection->showrooms->modelKeys(),
                    ])->values()
                : [],
            'can' => [
                'create' => $user->can('create', Source::class),
                'manageConnections' => $manageConnections,
            ],
        ]);
    }

    public function store(SourceRequest $request): RedirectResponse
    {
        $data = $request->sourceData();

        Gate::authorize('createIn', [Source::class, $data['showroom_id']]);

        $source = $this->sources->createSource($data, $request->connectionData());

        TableQuery::flashSuccess("Источник «{$source->name}» создан.");

        return back();
    }

    public function update(SourceRequest $request, Source $source): RedirectResponse
    {
        $data = $request->updateData();

        Gate::authorize('createIn', [Source::class, $data['showroom_id']]);

        $source = $this->sources->updateSource($source, $data, $request->connectionData());

        TableQuery::flashSuccess("Источник «{$source->name}» сохранён.");

        return back();
    }

    public function status(StatusRequest $request, Source $source): RedirectResponse
    {
        $source = $this->sources->setSourceActive($source, $request->boolean('active'), $request->validated('lock_version'));

        TableQuery::flashSuccess($source->active
            ? "Источник «{$source->name}» включён."
            : "Источник «{$source->name}» отключён.");

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Source $source, bool $canUpdate, bool $manageConnections): array
    {
        $mango = $source->connectionBindings->first();

        return [
            'id' => $source->id,
            'code' => $source->code,
            'name' => $source->name,
            'url' => $source->url,
            'note' => $source->note,
            'showroom' => $this->option($source->showroom),
            'kind' => $this->option($source->kind),
            'classification' => $source->classification ? $this->option($source->classification) : null,
            'legacy_classification' => $source->legacyClassification?->name,
            'agency' => $source->agency ? $this->option($source->agency) : null,
            'channels' => $source->channels->map(fn (ContactChannel $channel) => $this->option($channel))->values(),
            'site_intake' => $source->siteIntake ? [
                'active' => $source->siteIntake->active,
                'verification_status' => $source->siteIntake->verification_status,
                'has_key' => $manageConnections ? $source->siteIntake->key_hash !== null : null,
            ] : null,
            'internal' => $source->transfer_from_showroom_id !== null,
            'mango' => $mango ? [
                'connection' => $this->option($mango->connection),
                'lines' => $mango->lines->map(fn (IntegrationLine $line) => ['type' => $line->type, 'value' => $line->value])->values(),
            ] : null,
            'active' => $source->active,
            'lock_version' => $source->lock_version,
            'can' => ['update' => $canUpdate],
        ];
    }

    /**
     * @return array{id: int, name: string, active: bool}
     */
    private function option(Showroom|SourceKind|SourceClassification|ContactChannel|Agency|IntegrationConnection $model): array
    {
        return ['id' => $model->id, 'name' => $model->name, 'active' => $model->active];
    }

    /**
     * @param  Collection<int, covariant Showroom|SourceClassification|ContactChannel|Agency>  $models
     * @return list<array{id: int, name: string, active: bool}>
     */
    private function options(Collection $models): array
    {
        return array_values($models->map(fn ($model) => $this->option($model))->all());
    }
}
