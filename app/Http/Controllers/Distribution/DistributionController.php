<?php

namespace App\Http\Controllers\Distribution;

use App\Enums\DistributionResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Distribution\DistributionScheduleRequest;
use App\Models\DistributionSchedule;
use App\Models\DistributionScheduleException;
use App\Models\Order;
use App\Models\Showroom;
use App\Models\User;
use App\Services\AccessScope;
use App\Services\CallbackDistributor;
use App\Services\DistributionScheduleManager;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DistributionController extends Controller
{
    public function __construct(
        private readonly AccessScope $scope,
        private readonly CallbackDistributor $distributor,
        private readonly DistributionScheduleManager $schedules,
    ) {}

    /**
     * Schedules of one showroom chosen among the viewer's showrooms: the employees with
     * access to it, each with his participation and schedule there only.
     */
    public function schedules(Request $request): Response
    {
        $this->authorize('distribution-schedules.view');

        /** @var User $viewer */
        $viewer = $request->user();
        $showrooms = $this->scope->showrooms(Showroom::query(), $viewer)->orderBy('name')->get(['id', 'name', 'active']);
        $selected = $showrooms->firstWhere('id', (int) $request->query('showroom')) ?? $showrooms->first();
        $canManage = $selected !== null && $this->schedules->canManage($viewer, $selected);
        $visible = $showrooms->modelKeys();

        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::text(['users.name']),
        ]);

        $paginator = $selected === null
            ? null
            : $filters->apply($this->schedules->employees($selected, $viewer))
                ->leftJoin('distribution_schedules as schedule', fn ($join) => $join
                    ->on('schedule.user_id', '=', 'users.id')
                    ->where('schedule.showroom_id', '=', $selected->id))
                ->select('users.*')
                ->with(['distributionSchedules' => fn ($query) => $query->with('exceptions')->with('showroom:id,name')])
                ->orderByRaw('coalesce(schedule.participates, 0) desc')
                ->orderBy('users.name')
                ->orderBy('users.id')
                ->paginate(TableQuery::perPage($request))
                ->withQueryString();

        return Inertia::render('distribution/schedules', [
            'members' => $paginator === null ? null : TableQuery::paginated($paginator, function (User $user) use ($selected, $canManage, $visible) {
                $schedule = $user->distributionSchedules->firstWhere('showroom_id', $selected->id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'active' => $user->active,
                    'participates' => $schedule->participates ?? false,
                    'weekdays' => ($schedule ?? new DistributionSchedule)->weekdays(),
                    'exceptions' => ($schedule?->exceptions ?? collect())->map(fn (DistributionScheduleException $exception) => ['date' => $exception->date, 'works' => $exception->works])->values(),
                    'lock_version' => $schedule->lock_version ?? 0,
                    'copy_from' => $canManage
                        ? $user->distributionSchedules
                            ->where('showroom_id', '!=', $selected->id)
                            ->whereIn('showroom_id', $visible)
                            ->map(fn (DistributionSchedule $other) => ['id' => $other->showroom_id, 'name' => $other->showroom->name])
                            ->sortBy('name')
                            ->values()
                        : [],
                ];
            }),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
            'showrooms' => $showrooms->map(fn (Showroom $showroom) => ['id' => $showroom->id, 'name' => $showroom->name, 'active' => $showroom->active])->values(),
            'showroom' => $selected?->id,
            'can' => ['manage' => $canManage],
        ]);
    }

    public function updateSchedule(DistributionScheduleRequest $request, Showroom $showroom, User $user): RedirectResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        $this->schedules->save($viewer, $user, $showroom, $request->scheduleData());

        TableQuery::flashSuccess("График сотрудника «{$user->name}» в салоне «{$showroom->name}» сохранён.");

        return back();
    }

    public function copySchedule(Request $request, Showroom $showroom, User $user): RedirectResponse
    {
        $this->authorize('distribution-schedules.manage');

        $data = $request->validate([
            'from_showroom_id' => ['required', 'integer'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $from = Showroom::query()->find($data['from_showroom_id']);

        if ($from === null) {
            return back()->withErrors(['from_showroom_id' => 'Выберите другой салон из вашей области доступа.']);
        }

        $this->schedules->copy($viewer, $user, $showroom, $from, (int) $data['lock_version']);

        TableQuery::flashSuccess("График сотрудника «{$user->name}» скопирован из салона «{$from->name}».");

        return back();
    }

    /**
     * Hands one order to distribution, or retries a waiting one.
     */
    public function distribute(Request $request, int $order): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->scope->orders(Order::query(), $user)->findOrFail($order);

        $this->authorize('distribute', $model);

        $result = $this->distributor->request($model, $user);

        TableQuery::flashSuccess(match ($result) {
            DistributionResult::Assigned => "Заявка №{$model->id} назначена: {$model->fresh()?->responsible?->name}.",
            DistributionResult::Expired => "Заявка №{$model->id}: расчётный день закончился, назначьте ответственного вручную.",
            DistributionResult::Waiting => "Заявка №{$model->id} ожидает распределения: сейчас назначить некому.",
            default => "Заявка №{$model->id} уже не ожидает распределения.",
        });

        return back();
    }
}
