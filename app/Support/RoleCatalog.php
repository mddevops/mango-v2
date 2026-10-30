<?php

namespace App\Support;

use App\Support\PermissionCatalog as P;

/**
 * Initial roles. Used only to seed roles that do not exist yet; afterwards roles
 * and their permissions are edited through the interface.
 */
final class RoleCatalog
{
    public const ADMINISTRATOR = 'administrator';

    /**
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public static function defaults(): array
    {
        return [
            self::ADMINISTRATOR => [
                'label' => 'Администратор',
                'permissions' => P::all(),
            ],
            'supervisor' => [
                'label' => 'Руководитель колл-центра',
                'permissions' => [
                    P::WORKSPACE_VIEW,
                    P::SHOWROOMS_VIEW,
                    P::DEPARTMENTS_VIEW,
                    P::USERS_VIEW,
                    P::USERS_MANAGE,
                    P::SOURCES_VIEW,
                ],
            ],
            'operator' => [
                'label' => 'Оператор',
                'permissions' => [
                    P::WORKSPACE_VIEW,
                ],
            ],
            'showroom_manager' => [
                'label' => 'Менеджер салона',
                'permissions' => [
                    P::WORKSPACE_VIEW,
                    P::SHOWROOMS_VIEW,
                    P::DEPARTMENTS_VIEW,
                    P::USERS_VIEW,
                    P::USERS_MANAGE,
                    P::SOURCES_VIEW,
                ],
            ],
            'credit_specialist' => [
                'label' => 'Кредитный специалист',
                'permissions' => [
                    P::WORKSPACE_VIEW,
                ],
            ],
            'director' => [
                'label' => 'Руководитель',
                'permissions' => [
                    P::WORKSPACE_VIEW,
                    P::SHOWROOMS_VIEW,
                    P::DEPARTMENTS_VIEW,
                    P::USERS_VIEW,
                    P::AUDIT_VIEW,
                    P::SOURCES_VIEW,
                ],
            ],
        ];
    }
}
