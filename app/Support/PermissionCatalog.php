<?php

namespace App\Support;

/**
 * Permissions that exist at the current stage. Roles decide which actions are available;
 * showroom and department membership decides which data those actions can reach.
 */
final class PermissionCatalog
{
    public const WORKSPACE_VIEW = 'workspace.view';

    public const SHOWROOMS_VIEW = 'showrooms.view';

    public const SHOWROOMS_MANAGE = 'showrooms.manage';

    public const DEPARTMENTS_VIEW = 'departments.view';

    public const DEPARTMENTS_MANAGE = 'departments.manage';

    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const USERS_SET_PASSWORD = 'users.set_password';

    public const ROLES_MANAGE = 'roles.manage';

    public const ACCESS_GRANT_GLOBAL = 'access.grant_global';

    public const AUDIT_VIEW = 'audit.view';

    public const SOURCES_VIEW = 'sources.view';

    public const SOURCES_MANAGE = 'sources.manage';

    public const SOURCE_REFERENCES_VIEW = 'source_references.view';

    public const SOURCE_REFERENCES_MANAGE = 'source_references.manage';

    public const ORDER_REFERENCES_VIEW = 'order_references.view';

    public const ORDER_REFERENCES_MANAGE = 'order_references.manage';

    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_VIEW_ALL = 'orders.view_all';

    public const ORDERS_CREATE = 'orders.create';

    public const ORDERS_UPDATE = 'orders.update';

    public const ORDERS_ASSIGN = 'orders.assign';

    public const ORDERS_ASSIGN_ANY = 'orders.assign_any';

    public const ORDERS_SOURCE_SHOWROOM = 'orders.source_showroom';

    public const ORDERS_COMMENT = 'orders.comment';

    public const ORDERS_HISTORY = 'orders.history';

    public const ORDERS_RECORDINGS = 'orders.recordings';

    public const ORDERS_PREVIOUS = 'orders.previous';

    public const ORDERS_CROSS_SHOWROOM = 'orders.cross_showroom';

    public const SCHEDULES_VIEW = 'schedules.view';

    public const SCHEDULES_MANAGE = 'schedules.manage';

    public const DISTRIBUTION_RUN = 'distribution.run';

    public const ORDERS_TRANSFER = 'orders.transfer';

    public const CONNECTIONS_MANAGE = 'connections.manage';

    public const CONNECTIONS_SECRETS = 'connections.secrets';

    public const SUPPORT_MANAGE = 'support.manage';

    /**
     * @return list<array{key: string, label: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function groups(): array
    {
        return [
            [
                'key' => 'workspace',
                'label' => 'Рабочее место',
                'permissions' => [
                    ['name' => self::WORKSPACE_VIEW, 'label' => 'Просмотр рабочего места'],
                ],
            ],
            [
                'key' => 'showrooms',
                'label' => 'Автосалоны',
                'permissions' => [
                    ['name' => self::SHOWROOMS_VIEW, 'label' => 'Просмотр салонов'],
                    ['name' => self::SHOWROOMS_MANAGE, 'label' => 'Управление салонами'],
                ],
            ],
            [
                'key' => 'departments',
                'label' => 'Отделы',
                'permissions' => [
                    ['name' => self::DEPARTMENTS_VIEW, 'label' => 'Просмотр отделов'],
                    ['name' => self::DEPARTMENTS_MANAGE, 'label' => 'Управление отделами'],
                ],
            ],
            [
                'key' => 'users',
                'label' => 'Сотрудники',
                'permissions' => [
                    ['name' => self::USERS_VIEW, 'label' => 'Просмотр сотрудников'],
                    ['name' => self::USERS_MANAGE, 'label' => 'Управление сотрудниками'],
                    ['name' => self::USERS_SET_PASSWORD, 'label' => 'Установка нового пароля сотруднику'],
                ],
            ],
            [
                'key' => 'access',
                'label' => 'Права доступа',
                'permissions' => [
                    ['name' => self::ROLES_MANAGE, 'label' => 'Управление ролями'],
                    ['name' => self::ACCESS_GRANT_GLOBAL, 'label' => 'Выдача глобального доступа ко всем салонам'],
                ],
            ],
            [
                'key' => 'sources',
                'label' => 'Источники',
                'permissions' => [
                    ['name' => self::SOURCES_VIEW, 'label' => 'Просмотр источников'],
                    ['name' => self::SOURCES_MANAGE, 'label' => 'Управление источниками'],
                    ['name' => self::SOURCE_REFERENCES_VIEW, 'label' => 'Просмотр справочников источников'],
                    ['name' => self::SOURCE_REFERENCES_MANAGE, 'label' => 'Управление справочниками источников'],
                ],
            ],
            [
                'key' => 'order_references',
                'label' => 'Справочники заявок',
                'permissions' => [
                    ['name' => self::ORDER_REFERENCES_VIEW, 'label' => 'Просмотр справочников заявок'],
                    ['name' => self::ORDER_REFERENCES_MANAGE, 'label' => 'Управление справочниками заявок (только полный администратор)'],
                ],
            ],
            [
                'key' => 'orders',
                'label' => 'Заявки',
                'permissions' => [
                    ['name' => self::ORDERS_VIEW, 'label' => 'Просмотр своих заявок (где сотрудник ответственный)'],
                    ['name' => self::ORDERS_VIEW_ALL, 'label' => 'Просмотр всех заявок доступных салонов'],
                    ['name' => self::ORDERS_CREATE, 'label' => 'Создание заявок'],
                    ['name' => self::ORDERS_UPDATE, 'label' => 'Изменение заявок'],
                    ['name' => self::ORDERS_ASSIGN, 'label' => 'Назначение ответственного из салона заявки'],
                    ['name' => self::ORDERS_ASSIGN_ANY, 'label' => 'Назначение ответственного из всех салонов'],
                    ['name' => self::ORDERS_SOURCE_SHOWROOM, 'label' => 'Видеть салон источника при создании заявки'],
                    ['name' => self::ORDERS_COMMENT, 'label' => 'Добавление комментариев'],
                    ['name' => self::ORDERS_HISTORY, 'label' => 'Просмотр истории действий по заявке'],
                    ['name' => self::ORDERS_RECORDINGS, 'label' => 'Прослушивание записей разговоров (вместе с историей заявки)'],
                    ['name' => self::ORDERS_PREVIOUS, 'label' => 'Просмотр предыдущих обращений клиента в салоне'],
                    ['name' => self::ORDERS_CROSS_SHOWROOM, 'label' => 'Просмотр обращений клиента в других доступных салонах'],
                    ['name' => self::ORDERS_TRANSFER, 'label' => 'Передача заявки в другой доступный салон (вместе с изменением и созданием заявок)'],
                ],
            ],
            [
                'key' => 'distribution',
                'label' => 'Распределение',
                'permissions' => [
                    ['name' => self::SCHEDULES_VIEW, 'label' => 'Просмотр графиков и участия в распределении доступных салонов'],
                    ['name' => self::SCHEDULES_MANAGE, 'label' => 'Изменение графиков и участия в распределении доступных салонов'],
                    ['name' => self::DISTRIBUTION_RUN, 'label' => 'Отправка заявок в распределение (вместе с просмотром всех заявок)'],
                ],
            ],
            [
                'key' => 'connections',
                'label' => 'Подключения',
                'permissions' => [
                    ['name' => self::CONNECTIONS_MANAGE, 'label' => 'Управление подключениями (только полный администратор)'],
                    ['name' => self::CONNECTIONS_SECRETS, 'label' => 'Ввод и удаление секретов подключений (только полный администратор)'],
                ],
            ],
            [
                'key' => 'support',
                'label' => 'Поддержка',
                'permissions' => [
                    ['name' => self::SUPPORT_MANAGE, 'label' => 'Решение обращений в поддержку (видит все обращения и отвечает на них)'],
                ],
            ],
            [
                'key' => 'audit',
                'label' => 'Аудит',
                'permissions' => [
                    ['name' => self::AUDIT_VIEW, 'label' => 'Просмотр аудита'],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map(
            fn (array $group) => array_column($group['permissions'], 'name'),
            self::groups(),
        ));
    }

    public static function label(string $permission): string
    {
        foreach (self::groups() as $group) {
            foreach ($group['permissions'] as $item) {
                if ($item['name'] === $permission) {
                    return $item['label'];
                }
            }
        }

        return $permission;
    }
}
