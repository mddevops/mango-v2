<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Services\AccessScope;
use App\Support\PermissionCatalog as P;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    private const SUBJECTS = [
        'showroom' => 'Салон',
        'department' => 'Отдел',
        'user' => 'Сотрудник',
        'role' => 'Роль',
        'source' => 'Источник',
        'source_kind' => 'Вид источника',
        'source_classification' => 'Классификация источника',
        'contact_channel' => 'Канал обращения',
        'agency' => 'Агентство',
        'integration_connection' => 'Подключение',
        'distribution_member' => 'Участие в распределении',
        'distribution_schedule' => 'График распределения',
        'support_ticket' => 'Обращение в поддержку',
        'site' => 'Сайт (до перехода на источники)',
        'site_type' => 'Тип сайта (до перехода на источники)',
    ];

    public function index(Request $request, AccessScope $scope): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        $orderHistory = $request->user()->can(P::ORDERS_HISTORY);
        $subjects = $orderHistory ? [...self::SUBJECTS, 'order' => 'Заявка'] : self::SUBJECTS;

        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['subject_label', 'actor_name', 'description']),
            'subject' => TableFilter::column('subject_type', array_keys($subjects)),
        ]);

        $paginator = $filters->apply($scope->auditLogs(AuditLog::query(), $request->user()))
            ->when(! $orderHistory, fn ($query) => $query->where('subject_type', '!=', 'order'))
            ->when($orderHistory, fn ($query) => $query->where(fn (Builder $entries) => $entries
                ->where('subject_type', '!=', 'order')
                ->orWhereIn('subject_id', $scope->orders(Order::query(), $request->user())->select('orders.id'))))
            ->orderByDesc('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('audit/index', [
            'entries' => TableQuery::paginated($paginator, fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->toISOString(),
                'actor' => $log->actor_name,
                'event' => $log->event,
                'subject_type' => $subjects[$log->subject_type] ?? $log->subject_type,
                'subject' => $log->subject_label,
                'description' => $log->description,
                'changes' => $log->changes,
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'subjects' => collect($subjects)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }
}
