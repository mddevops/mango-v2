<?php

namespace App\Http\Middleware;

use App\Models\IntegrationConnection;
use App\Services\AccessScope;
use App\Services\SupportDesk;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at?->toISOString(),
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                    'created_at' => $user->created_at?->toISOString(),
                    'updated_at' => $user->updated_at?->toISOString(),
                ] : null,
                'permissions' => $user ? $user->getAllPermissions()->pluck('name')->values() : [],
                'globalAccess' => $user ? app(AccessScope::class)->isGlobal($user) : false,
                'abilities' => [
                    'manageConnections' => $user?->can('viewAny', IntegrationConnection::class) ?? false,
                    'viewOrderReferences' => $user?->can('order-references.view') ?? false,
                    'viewSourceReferences' => $user?->can('source-references.view') ?? false,
                    'viewSchedules' => $user?->can('distribution-schedules.view') ?? false,
                ],
            ],
            'supportUnread' => fn () => $user ? app(SupportDesk::class)->unreadCount($user) : 0,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
