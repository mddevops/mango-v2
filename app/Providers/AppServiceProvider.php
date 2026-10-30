<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AccessScope;
use App\Services\AdministratorGuard;
use App\Support\PermissionCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
    }

    /**
     * Source and order reference tables are shared by every showroom, so changing them
     * requires global access, like shared departments. Order references drive the order
     * workflow, so only a full administrator holding the permission may change them; this
     * one gate covers routes, form requests and the `can.manage` flag of the pages.
     */
    protected function configureGates(): void
    {
        Gate::define('source-references.view', fn (User $user): bool => $user->can(PermissionCatalog::SOURCE_REFERENCES_VIEW));

        Gate::define('source-references.manage', fn (User $user): bool => $user->can(PermissionCatalog::SOURCE_REFERENCES_MANAGE)
            && app(AccessScope::class)->isGlobal($user));

        Gate::define('order-references.view', fn (User $user): bool => $user->can(PermissionCatalog::ORDER_REFERENCES_VIEW));

        Gate::define('order-references.manage', fn (User $user): bool => $user->can(PermissionCatalog::ORDER_REFERENCES_MANAGE)
            && app(AdministratorGuard::class)->isFullAdministrator($user));

        Gate::define('distribution-schedules.view', fn (User $user): bool => $user->can(PermissionCatalog::SCHEDULES_VIEW));

        // Each schedule belongs to one showroom; access to that showroom is checked per action.
        Gate::define('distribution-schedules.manage', fn (User $user): bool => $user->can(PermissionCatalog::SCHEDULES_VIEW)
            && $user->can(PermissionCatalog::SCHEDULES_MANAGE));

        Gate::define('distribution-run', fn (User $user): bool => $user->can(PermissionCatalog::DISTRIBUTION_RUN)
            && $user->can(PermissionCatalog::ORDERS_VIEW)
            && $user->can(PermissionCatalog::ORDERS_VIEW_ALL));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
