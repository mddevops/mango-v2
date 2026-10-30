<?php

namespace App\Http\Controllers\Sources;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StatusRequest;
use App\Http\Requests\ReorderRequest;
use App\Http\Requests\Sources\SourceReferenceRequest;
use App\Models\Agency;
use App\Models\ContactChannel;
use App\Models\SourceClassification;
use App\Models\SourceKind;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\ReferenceOrder;
use App\Services\SourceReferenceManager;
use App\Support\SourceReferenceType;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SourceReferenceController extends Controller
{
    public function __construct(
        private readonly SourceReferenceManager $references,
        private readonly AccessScope $scope,
    ) {}

    public function index(Request $request, SourceReferenceType $type): Response
    {
        Gate::authorize('source-references.view');

        /** @var User $user */
        $user = $request->user();

        $isKinds = $type === SourceReferenceType::Kinds;
        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text($type->hasCode() ? ['name', 'code'] : ['name']),
            'status' => TableFilter::status(),
        ]);

        $query = $filters->apply($type->model()::query())
            ->when($isKinds, fn (Builder $query) => $query->with(['classifications:id,name', 'channels:id,name']))
            ->withCount(['sources' => fn (Builder $sources) => $this->scope->sources($sources, $user)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id');

        $paginator = $query->paginate(TableQuery::perPage($request))->withQueryString();
        $canManage = Gate::allows('source-references.manage');

        return Inertia::render('source-references/index', [
            'type' => $type->value,
            'title' => $type->title(),
            'singular' => $type->singular(),
            'hasCode' => $type->hasCode(),
            'tabs' => array_map(fn (SourceReferenceType $tab) => ['value' => $tab->value, 'title' => $tab->title()], SourceReferenceType::cases()),
            'items' => TableQuery::paginated($paginator, fn (SourceKind|SourceClassification|ContactChannel|Agency $item) => [
                'id' => $item->id,
                'code' => $item instanceof Agency ? null : $item->code,
                'name' => $item->name,
                'icon' => $item instanceof SourceKind || $item instanceof ContactChannel ? $item->icon : null,
                'show_icon' => ($item instanceof SourceKind || $item instanceof ContactChannel) && $item->show_icon,
                'icon_color' => $item instanceof SourceKind || $item instanceof ContactChannel ? $item->icon_color->value : null,
                'active' => $item->active,
                'sources_count' => (int) $item->getAttribute('sources_count'),
                'classification_ids' => $item instanceof SourceKind ? $item->classifications->modelKeys() : [],
                'channel_ids' => $item instanceof SourceKind ? $item->channels->modelKeys() : [],
                'classification_names' => $item instanceof SourceKind ? $item->classifications->sortBy('name')->pluck('name')->values() : [],
                'channel_names' => $item instanceof SourceKind ? $item->channels->sortBy('name')->pluck('name')->values() : [],
                'lock_version' => $item->lock_version,
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'classifications' => $isKinds ? SourceClassification::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'active']) : [],
            'channels' => $isKinds ? ContactChannel::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'active']) : [],
            'can' => ['manage' => $canManage],
        ]);
    }

    public function store(SourceReferenceRequest $request, SourceReferenceType $type): RedirectResponse
    {
        $item = $this->references->create($type, $request->createData());

        TableQuery::flashSuccess("{$type->singular()} «{$item->name}» {$type->phrases()['created']}.");

        return back();
    }

    public function update(SourceReferenceRequest $request, SourceReferenceType $type, int $id): RedirectResponse
    {
        $item = $this->references->update($type, $id, $request->updateData());

        TableQuery::flashSuccess("{$type->singular()} «{$item->name}» {$type->phrases()['updated']}.");

        return back();
    }

    public function reorder(ReorderRequest $request, SourceReferenceType $type, ReferenceOrder $order): RedirectResponse
    {
        $order->reorder($type->model(), $request->ids(), $type->auditSubject(), $type->title(), ['sort_order', 'name', 'id']);

        return back();
    }

    public function status(StatusRequest $request, SourceReferenceType $type, int $id): RedirectResponse
    {
        $active = $request->boolean('active');
        $item = $this->references->setActive($type, $id, $active, $request->validated('lock_version'));

        TableQuery::flashSuccess("{$type->singular()} «{$item->name}» {$type->phrases()[$item->active ? 'activated' : 'deactivated']}.");

        return back();
    }
}
