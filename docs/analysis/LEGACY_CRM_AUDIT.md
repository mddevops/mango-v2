# ACP CRM: аудит репозитория и предложение архитектуры V2

Дата: 19 октября 2026. Источник: `dilovar-91/acp_crm`, ветка `master`, commit `87a1672dea62b54802b6fd212a7ca43130b9efe2`.

## 1. Что проверено и как читать документ

Это статический аудит исходного кода старой CRM, а не отчёт о работающем production и не команда Cursor на реализацию. Новый пользовательский проект на OSPanel здесь не проверялся и не менялся. Старый репозиторий получен отдельной копией; приложение не запускалось, зависимости не устанавливались, тесты и миграции не выполнялись, внешние API и рабочая БД не вызывались.

В репозитории 727 отслеживаемых файлов, из них 241 в `app/`, 335 в `client/`, 69 PHP-контроллеров и 82 модели. Выполнена инвентаризация всех этих областей: маршрутов, методов, моделей, связей и клиентских страниц. Основные сквозные сценарии ниже прослежены чтением соответствующих серверных и клиентских участков. Повторяющиеся импорты сопоставлены по выборке источников, формату получения, назначению салона, маркерам происхождения и событиям. Это не означает независимую построчную ручную верификацию каждого метода каждого файла.

Метки:

- **Факт кода** — непосредственно видимое поведение указанной версии.
- **Вывод** — последствие структуры или алгоритма; не результат воспроизведения на production.
- **Предложение V2** — рекомендуемое изменение, которое ещё нужно принять.
- **Неизвестно** — сведения, которых нет в доступном коде или для которых нужны данные/конфигурация/прогон.

Нельзя обещать «в точности всё поведение production» по одному Git: основные бизнес-таблицы не имеют полного набора миграций, справочники и права заполняются данными БД, действующий cron, workers и переменные окружения неизвестны. В приложении также есть шаблонные, тестовые и исторические файлы. Наличие контроллера не доказывает использование функции. Реестр в приложениях документа — карта для проверки, не отметка о готовности всех функций.

Все ссылки на код закреплены на указанном commit. Секретные значения, персональные данные и содержимое `.env` в отчёт не включены.

## 2. Главный результат

Старое [Site](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Site.php) одновременно содержит рекламную атрибуцию, принадлежность салону, телефоны/SIP, параметры загрузки лидов и ключи аналитических интеграций. Кроме того, `Order.source_id` обозначает происхождение обработчика, а `Order.type_id` используется для назначения заявки и отдельных каналов. Универсальной чистой модели «источник → обращение → работа салона» нет.

**Предложение:** в V2 использовать понятный пользователю раздел «Источники». Конкретный сайт будет одним из видов источника наряду с ботом, VK, площадкой объявлений, партнёром и ручным вводом. Развести вид источника, маркетинговую классификацию, канал обращения, цель обращения, агентство и техническое подключение. Одного переименования таблицы недостаточно.

При этом сохраняются полезные сценарии: атрибуция салону, повторные обращения, сохранение ответственного, распределение по нагрузке и графику, всплывающее уведомление звонка, фиксация реального ответившего, история разговора/записи, передача в приезд/кредит, аналитические выгрузки и отчёты.

## 3. Общая карта старого приложения

| Область               | Что есть в коде                                                                                              | Основные точки                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| --------------------- | ------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Авторизация           | Sanctum, сессии, вход по email/паролю, impersonation                                                         | [AuthController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php), [User](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/User.php), [client/nuxt.config.js](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/nuxt.config.js)                                                                                                                                                                                                                       |
| Права                 | Bouncer abilities и одновременно числовой `role_id`; права страниц на клиенте                                | `Auth/PermissionController`, [PageController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/PageController.php), [client/plugins/vue-gates.js](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/plugins/vue-gates.js)                                                                                                                                                                                                                                                                                               |
| Салоны и сотрудники   | `showroom_id` сотрудника, рабочее место/внутренний номер, активность, два вида недельных графиков            | [ShowroomController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php), [UserController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php), [OperatorController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php), [CrmController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CrmController.php)                 |
| Источники             | Сайты, типы сайтов, агентства, токены, адреса импорта, телефоны, SIP                                         | [Site](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Site.php), [ShowroomController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php), [client/pages/admin/sites.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/sites.vue)                                                                                                                                                                                                 |
| Лиды                  | Формы, квизы, VK, партнёры, импорты, ручной ввод, звонки, завершённые чаты                                   | [LeadController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php), [TildaController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TildaController.php), `Import*`, [JivoWebhookController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/JivoWebhookController.php), [MangoCallService](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/Mango/MangoCallService.php)  |
| Распределение         | Сохранение прежнего оператора при повторе либо минимум заявок за период среди сотрудников по графику         | `OrderAdded*EventListener`, [OrderDistributeService](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/OrderDistributeService.php), [DistributeService](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/DistributeService.php)                                                                                                                                                                                                                                                                                           |
| Карточка              | Телефоны, машина/потребность, кредитные поля, комментарии, статусы, даты перезвона/приезда, повторы, история | [OrderController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php), [Order](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Order.php), [client/pages/crm/order_edit.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/order_edit.vue)                                                                                                                                                                                               |
| Переносы              | Копирование в другой салон, отложенная покупка, массовое переназначение                                      | [OrderController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php), [DeferredPurchase](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/DeferredPurchase.php), [ReturnDeferredPurchases](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ReturnDeferredPurchases.php)                                                                                                                                                                |
| Телефония             | Mango realtime/summary/recordings, click-to-call, поиск по SIP/линии, пропущенные                            | [MangoWebhookController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php), `Services/Mango/*`, [RecordController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/RecordController.php)                                                                                                                                                                                                                                                                                     |
| Аналитические системы | Отправка результатов в Calltouch и Mango Calltracking, входящее обогащение UTM                               | [DealUpdate](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Jobs/DealUpdate.php), [CalltouchDealUpdateService](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/CalltouchDealUpdateService.php), [MangoDealUpdateService](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/MangoDealUpdateService.php), [MangoCallTrackingController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoCallTrackingController.php) |
| Приезды               | Отдельные записи, менеджер, факт приезда, консультация, копирование в кредит                                 | [ArrivalController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php), [Arrival](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Arrival.php), tablet pages                                                                                                                                                                                                                                                                                                                                 |
| Кредит/продажи        | Решения банков колонками, созаёмщик, ручные результаты, продажа и контроль после продажи                     | [CreditRequestController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php), [CreditRequest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CreditRequest.php)                                                                                                                                                                                                                                                                                                                       |
| Склад                 | Новые и БУ, стоимость, документы, подготовка, фото, перемещения                                              | [CarController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarController.php), [UsedCarController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php), [TransitController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php)                                                                                                                                                                    |
| Логистика             | Отправки, водители, компании, состав отправки                                                                | [ShipmentController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php)                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| Отчёты                | Операторы, салоны, сайты, агентства, статусы, причины, UTM, одобрения, экспорты                              | [ReportController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php), [SummaryReportController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php), [VictoryReportController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php), `Exports/*`                                                                                                                         |
| Дополнительное        | SMS, Telegram, чёрный список, реестр рекламных проектов, расходы/записи Fare                                 | `ProstorSms*`, Notifications, `Blacklist*`, [ProjectController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProjectController.php), [FareController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/FareController.php)                                                                                                                                                                                                                                                                           |

**Стек по декларациям:** Laravel `^8.75`, PHP `^8.1`, Nuxt `^2.15.8`, Vue `^2.7.15`, Vuetify `^2.6.12`. Это диапазоны зависимостей, не доказательство фактически установленных версий production. Nuxt работает как SPA (`ssr: false`), клиентские запросы идут через Axios, а изменения рассылаются через broadcast/Pusher-совместимый транспорт. В конфигурации Laravel timezone `Europe/Moscow`; реальное окружение очередей/кеша не выводится из значений по умолчанию `sync`/`file`.

## 4. Организация, сотрудники, права

### 4.1. Факты

[User](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/User.php) связан с одним салоном через `showroom_id` и одной числовой ролью через `role_id`. Дополнительно использует Bouncer `HasRolesAndAbilities`. В коде сосуществуют [Operator](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Operator.php), [Manager](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Manager.php) и [User](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/User.php): нельзя автоматически считать, что все внешние ключи операторов/менеджеров относятся к одной таблице.

Вход: [AuthController::login](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php#L17) вызывает `Auth::attempt(email,password)` и регенерирует сессию. В этом методе нет проверки активности. `impersonate` явно разрешён `role_id=1`; сохраняется исходный пользователь сессии. Возврат из impersonation предусмотрен отдельным методом.

[CrmController::setWorkPlace](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CrmController.php#L10) сохраняет выбранный `work_place` текущему сотруднику. Mango затем сопоставляет внутренний номер с этим полем. Два графика — [OperatorSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OperatorSchedule.php) и [ProgrammaticSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ProgrammaticSchedule.php) — содержат флаги дней недели, а не полноценные смены с датами и интервалами.

[PermissionController::saveUserPermissions](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L133) проверяет администратора и меняет permissions транзакцией; другие методы этого же контроллера используют иной подход и не всегда имеют такую проверку. `sync` копирует права/роли пользователя и делает `Cache::flush`; маршрут `sync-user` объявлен вне группы `auth:sanctum`.

### 4.2. Предложение V2

Использовать уже созданную в V2 систему ролей и областей доступа. Не переносить числовые роли, отдельные несовместимые справочники сотрудников и права, зашитые в URL страниц. Обслуживаемые салоны оператора должны быть явными. Членство в общем колл-центре не должно автоматически давать полномочия администратора.

Графики: недельный шаблон + исключения на конкретную дату; при необходимости смены с началом/концом и перерывами. Привязка внутреннего номера должна учитывать аккаунт АТС, а при сменной работе — актуальное назначение рабочего места. Номер `100` разных АТС не является одной и той же сущностью.

## 5. Источники: что действительно хранится в старой версии

### 5.1. Поля

[Site::$fillable](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Site.php): `title`, `phone`, `second_phone`, `url`, `pic`, `description`, `showroom_id`, `to_showroom`, `type_id`, `agency_id`, `manager_id`, `active`, `actual`, `order`, `token`, `post_url`, `import`, `calltouch_access_key`, `calltouch_site_id`, `mango_site_id`, `mango_token`, `sip`.

Контроллер дополнительно записывает `autospot_id`. Следовательно, одного чтения `$fillable` недостаточно для восстановления схемы. Два массива не являются описанием типов, индексов и NULL-ограничений существующей БД.

В [ShowroomController::saveSite/updateSite](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php) Calltouch/Mango/SIP сохраняются вместе с основной карточкой. `duplicateSite` копирует запись, очищая часть интеграционных идентификаторов и токенов. `deleteSite` физически удаляет сайт. `admin_sites` и обычный список используют кеши. В [client/pages/admin/sites.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/sites.vue) есть запрошенные пользователем поля и отображение Mango token в таблице.

### 5.2. Почему поля нельзя просто удалить

| Старое поле                    | Подтверждённое использование                                                                                                                                                  | Предложение V2                                                                     |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `showroom_id`                  | Салон источника, фильтрация, импорты                                                                                                                                          | Явная принадлежность источника и отдельные правила маршрутизации при необходимости |
| `to_showroom`                  | Дополнительная адресация/фильтрация ([Order::scopeToShowroom](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Order.php#L607)) | Отдельное правило назначения, не второе неоднозначное поле                         |
| `type_id`                      | Тип сайта; тип 4 исключён из части нагрузки                                                                                                                                   | Справочник классификации + явная настройка учёта нагрузки                          |
| `agency_id`                    | Подрядчик, фильтры отчётов и выбор импортера по id                                                                                                                            | Агентство отдельно от выбранного адаптера                                          |
| `phone/second_phone/sip`       | Определение сайта по линии/SIP Mango                                                                                                                                          | Множество привязок линий источника в рамках подключения                            |
| `token/post_url/import`        | Получение лидов от партнёров                                                                                                                                                  | Настройки подключения, направление pull/push, курсор и состояние                   |
| `autospot_id`                  | Поиск источника по входящему `ext_id`                                                                                                                                         | Внешний идентификатор в пределах провайдера/подключения                            |
| `calltouch_site_id/access_key` | Выгрузка статусов сделки в Calltouch                                                                                                                                          | Подключение аналитики и привязка внешнего проекта                                  |
| `mango_site_id/token`          | Выгрузка сделок в Mango Calltracking                                                                                                                                          | Отдельно от аккаунта Mango VPBX                                                    |

## 6. Поступление заявок: матрица сценариев

Ниже подтверждённые семейства. `source_id` старой заявки — числовая метка обработчика; это не то же самое, что новый бизнес-источник. Значения в таблице не являются предписанием использовать эти id в V2.

| Вход                                                                                                                                                       | Определение салона/сайта                                                                                                                            | Что делает старый код                                                                                                                                                                                                                                                                                    | Особенность                                                                                                                                                            |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [LeadController::lead](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L32)    | Салон из URL, сайт из тела                                                                                                                          | Новая [Order](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Order.php), телефон, интерес, UTM/history/referrer, событие [OrderCreated](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderCreated.php) | `source_id=2`                                                                                                                                                          |
| [LeadController::create](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L169) | `showroom_id/site_id` из тела                                                                                                                       | Новая заявка и событие                                                                                                                                                                                                                                                                                   | `source_id=7`                                                                                                                                                          |
| `pairDistribute`                                                                                                                                           | Заданные группы салонов + [LastQueue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/LastQueue.php) | Выбирает салон по состоянию очереди, сохраняет заявку                                                                                                                                                                                                                                                    | В коде конкретные группы и id; механизм не доказывает безопасную конкурентную раздачу                                                                                  |
| Tilda `create`                                                                                                                                             | Салон/сайт из URL                                                                                                                                   | Новая заявка                                                                                                                                                                                                                                                                                             | `type_id=2`                                                                                                                                                            |
| Tilda `createLead`                                                                                                                                         | Пара из URL                                                                                                                                         | Разбор полей, UTM, создание заявки                                                                                                                                                                                                                                                                       | Проверка обязательных полей не равна проверке принадлежности сайта салону                                                                                              |
| Tilda `createDistribute`                                                                                                                                   | URL + чередование [LastQueue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/LastQueue.php)         | Новая заявка в выбранном салоне                                                                                                                                                                                                                                                                          | Логика частично повторяет другие обработчики                                                                                                                           |
| Quiz                                                                                                                                                       | Пара из URL                                                                                                                                         | Новая заявка с данными квиза                                                                                                                                                                                                                                                                             | [QuizController::quiz](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/QuizController.php#L14), `source_id=6` |
| Victory push                                                                                                                                               | По `Site.token`                                                                                                                                     | Новая заявка                                                                                                                                                                                                                                                                                             | Неизвестный ключ даёт fallback салона и сайта 6                                                                                                                        |
| VK / Albato                                                                                                                                                | URL или тело, в отдельных методах группы                                                                                                            | Новая заявка с UTM и данными клиента                                                                                                                                                                                                                                                                     | Несколько обработчиков с разными маркерами `source_id`                                                                                                                 |
| Target                                                                                                                                                     | Тело и заданные группы                                                                                                                              | Потребность, UTM, детали кампании, новая заявка                                                                                                                                                                                                                                                          | Отдельный контроллер и одноимённый метод в LeadController; маршрут указывает на конкретную реализацию                                                                  |
| Autospot                                                                                                                                                   | `Site.autospot_id = ext_id`                                                                                                                         | Новая заявка, дополнительные ответы в комментарии                                                                                                                                                                                                                                                        | Если сайт не найден, fallback салона 12; `source_id=316`                                                                                                               |
| UDPauto                                                                                                                                                    | Фиксированные салон/сайт                                                                                                                            | Новая заявка                                                                                                                                                                                                                                                                                             | Привязки зашиты в обработчике                                                                                                                                          |
| CSV                                                                                                                                                        | Салон/сайт из запроса                                                                                                                               | Построчное создание и [OrderCreated](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderCreated.php)                                                                                                                                                    | Отдельный путь импорта                                                                                                                                                 |
| Excel CRM                                                                                                                                                  | Салон из запроса, сайт из фиксированной карты                                                                                                       | Чтение колонок, ошибки по строкам, [OrderExcelCreated](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderExcelCreated.php)                                                                                                                             | Отдельная логика повтора и распределения                                                                                                                               |
| Partner pull                                                                                                                                               | Сайт/агентство/token/post_url                                                                                                                       | HTTP-запрос → разбор пакета → новая заявка на элемент                                                                                                                                                                                                                                                    | Контроллеры `Import*`; разные форматы и курсоры                                                                                                                        |
| Mango realtime                                                                                                                                             | Аккаунт в URL, группа АТС, линия/SIP                                                                                                                | Создание входящей заявки при отсутствии существующей, уведомление, назначение ответившего                                                                                                                                                                                                                | Подробнее раздел 9                                                                                                                                                     |
| Mango summary                                                                                                                                              | Идентификатор звонка и телефон                                                                                                                      | Итог разговора/пропущенный/история/счётчик                                                                                                                                                                                                                                                               | Может создать отсутствующую входящую заявку                                                                                                                            |
| Mango Calltracking                                                                                                                                         | Салон URL + телефон клиента                                                                                                                         | Находит существующую заявку и дополняет UTM                                                                                                                                                                                                                                                              | Это обогащение, не независимое создание лида                                                                                                                           |
| Jivo                                                                                                                                                       | Пара из URL                                                                                                                                         | На `chat_finished` создаёт заявку, записывает переписку/UTM                                                                                                                                                                                                                                              | Это не живой операторский чат                                                                                                                                          |
| Ручное создание                                                                                                                                            | Поля формы                                                                                                                                          | Сохранение заявки, распределение, отложенная выгрузка сделки                                                                                                                                                                                                                                             | [OrderController::saveOrder](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L350)        |

**Вывод:** произвольный бот уже концептуально может отправить имя/телефон в общий обработчик, однако это не доказывает наличие отдельного корректно защищённого адаптера Telegram/JS AI/Авито/ДРОМ/АРМАНД. Именованные реализации и внешние форматы нужно подтверждать отдельно. Не надо объявлять провайдера поддержанным только потому, что существует общий endpoint.

### 6.1. Pull-импорты

Есть семейства Victory/VDL/VLL/VA, Justwe, Agency, InHousePV, Aura, Autodom, Autopole, Avangard и региональные варианты. Отбор идёт по `agency_id`, спискам `site.id`, токену и иногда активности. Данные обычно превращаются в новую [Order](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Order.php), затем запускается [OrderCreated](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderCreated.php) или [OrderPvCreated](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderPvCreated.php).

[LastRequest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/LastRequest.php) хранит `last_request_time` источника. В [ImportVaController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVaController.php) и [ImportRequestController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportRequestController.php) время сдвигается до обработки всего пакета. В [ImportJustweController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweController.php) курсор обновляется после HTTP-запроса, до сохранения отдельных элементов. Ошибка элемента может оставить курсор продвинутым. Идемпотентность по уникальному внешнему идентификатору не является общим механизмом этих импортов.

**Предложение:** сначала надёжно сохранить полученный пакет/элементы, затем продвигать подтверждённый курсор; повторно читать перекрывающийся интервал с дедупликацией по внешнему id. Для каждой интеграции отдельные курсор, расписание, checkpoint и статус ошибки. Не использовать id агентства как выбор HTTP-протокола.

## 7. Карточка заявки, повтор, распределение

### 7.1. Карточка

[Order](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Order.php) содержит контакт, до четырёх телефонных полей, потребность в автомобиле, цену, параметры оплаты/кредита, trade-in, занятость, регион, статус, причины, назначенного оператора, даты перезвона, ожидаемого/фактического приезда, UTM, точку входа и историю. Состав полей контроллера шире `$fillable`.

Клиентский `order_edit.vue` показывает полную карточку, историю, повторы, SMS, поездку/приезд; существуют облегчённые и специализированные версии карточки/списка. В `save` на клиенте часть статусов требует регион, дату перезвона или причину корзины. Наличие клиентской проверки не означает аналогичную серверную гарантию.

Важное смешение: `payment_methods` клиентской формы содержит не только «Наличными», «В кредит», «Лизинг», но и «Не дозвон», «Повтор», «Не ликвид». Следовательно, отчёты по способу оплаты частично считают качество/результат контакта.

**Предложение:** отдельно способ оплаты, этап работы, результат контакта, причина отказа, качество обращения и признак повторности. Названия/порядок меняются в справочниках; переходы используют стабильную семантику и права, а не id.

### 7.2. Повторы

Основной listener ищет совпадения телефона в пределах салона, сохраняет `retries = количество - 1`. Если оператор пустой или служебный 1000, пытается сохранить владельца другой найденной заявки при наличии графика, иначе распределяет. Разные scopes поиска телефона используют разные способы сопоставления и сортировки; часть — LIKE, часть — варианты номера, часть — приоритеты статусов. Нельзя назвать все эти пути единым алгоритмом «самая последняя заявка».

Формы обычно создают новую заявку. Mango ищет существующую в том же салоне по четырём телефонным полям и вариантам `7/+7/8`, сортирует по `updated_at`, затем id. Нового лида при совпадении обычно нет. Значит, число строк `orders` не равно числу фактических обращений.

### 7.3. Распределение

[OrderAddedEventListener](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Listeners/OrderAddedEventListener.php) выполняется как обычный listener, не как отдельная очередь `ShouldQueue`. Проверяет чёрный список/спам; для специальных записей прекращает обычное назначение. Для новых/повторных при отсутствии оператора выбирает по графику и нагрузке.

В обычном алгоритме: сотрудники ролей `[2,6]`, салон заявки, рабочий флаг соответствующего дня; счётчик заявок за расчётный интервал без `retries`, с исключением сайта `type_id=4`. Сортировка только по `orders_count`; дополнительного устойчивого критерия равенства нет. Проверки `is_online` в этом запросе нет. При отсутствии кандидата возвращается NULL. Назначение минимуму без общей блокировки может одновременно выбрать одного сотрудника в нескольких запросах.

Существуют близкие копии алгоритма в Excel/PV listeners и сервисах. [DistributeService](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/DistributeService.php) использует `programmaticSchedule` и иной состав счётчика. Нельзя механически слить их, не согласовав различия.

**Предложение:** один сервис распределения с явными пулами/политиками. Настройки: обслуживаемые салоны, доступность по смене, учитывать ли онлайн, какие обращения входят в нагрузку, сохранять ли ответственного при повторе, поведение при отсутствии кандидатов. Выбор и фиксация назначения атомарны. Для равной нагрузки — устойчивое правило очередности. Причина назначения сохраняется в журнале.

## 8. Расчётный день

**Факт старого кода:** в основном распределении граница 19:00, в условии для одного конкретного id — 18:30. После границы выбирается завтрашний день недельного графика, нагрузка считается с сегодняшней границы до завтрашней; до границы — с вчерашней до сегодняшней. Используется Moscow timezone приложения. Проверка сохранения прежнего оператора в `checkUserSchedule` использует 19:00 и не повторяет исключение. Это расхождение кода, а не предлагаемое правило V2.

Расчётная дата не записывается в заявку. Отчёты преимущественно фильтруют время создания; специальная кнопка периода лишь подставляет даты. Определение оператора Mango использует календарный день. Общего контракта времени нет.

**Согласованное направление V2:** каждый салон настраивает свою границу через интерфейс; исключений по id нет. Для суток достаточно одной границы и часового пояса, UI показывает «с … до …». Отключение переноса означает календарные сутки. Интервалы полуоткрытые: начало включено, конец исключён.

**Что ещё решить:** как называть расчётную дату — днём начала или окончания периода. Для сохранения старого выбора графика период после вечерней границы относится к следующему рабочему дню. Ранее обсуждённые исключения по классификации источника требуют явного переопределения, если всё ещё нужны пользователю; их нельзя считать отменёнными только из-за настройки салона. Пример 18:00 для классифайдов — требование пользователя к гибкости, а не найденное старое правило.

Сохранять `occurred_at`, `received_at`, вычисленную дату и снимок применённого правила. Не пересчитывать прошлые обращения при изменении настроек. Метрики поступления и распределения могут использовать разные даты; это должно быть видно в отчёте.

## 9. Mango: три разных механизма

### 9.1. АТС и звонки

Активные web routes направляют `call`, `summary`, `record/added` в [MangoWebhookController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php), а не в исторические [MangoController::checkPhone*](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php). Контроллер выбирает ключ/соль по id аккаунта через конфигурацию и вызывает `MangoHelper::getMethodData`. Точную криптографическую валидацию нужно проверять в пакете `dilovar-91/mango-office-api`: в этом аудите пакет не установлен и его исходники не исследовались.

[MangoCallData](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/Mango/MangoCallData.php): различает входящие/исходящие/внутренние; учитывает `callback_initiator`, `task_id`, `command_id` и `taken_from_call_id`, чтобы технический callback не создать как входящий лид. SIP не превращает в телефон клиента. Есть unit-тесты классификатора, но они здесь не запускались.

`handleRealtime`:

1. Проверяет наличие `entry_id/call_id`.
2. Берёт cache lock на аккаунт/entry.
3. Находит/создаёт MangoCall и хранит sequence по call_id.
4. Определяет салон по аккаунту, для некоторых ACD-групп берёт парный салон из helper.
5. Находит сайт по номеру линии/второму телефону/SIP.
6. На входящем `Appeared` в IVR/queue/abonent ищет существующую заявку; при отсутствии создаёт.
7. Один раз отправляет popup для entry.
8. На `Connected` у абонента определяет реального ответившего по внутреннему номеру, меняет оператора, ставит метку `source_id=25`, убирает уведомление.

`handleSummary`: отдельный cache lock + DB transaction/row lock. Проверяет `summary_processed`. Находит заявку/оператора/источник, при необходимости создаёт входящую. Разговор/пропуск определяет по `talk_time`; пишет историю, пропущенный, запускает счётчик звонков после commit. Сохраняет итог MangoCall и очищает popup.

Позитивные элементы для сохранения: уникальность аккаунт+entry в миграции, защита от повторного summary, seq по плечам, фиксация ответившего на Connected, afterCommit для части побочных действий.

Ограничения: realtime и summary используют разные ключи блокировки; первый realtime не обёрнут в общую DB-транзакцию; поздний realtime может конфликтовать с итогом. Это риск по коду, а не воспроизведённая здесь гонка. Нет универсального durable inbox всех входящих webhook до обработки. BroadcastNow может связать успех бизнес-операции с доступностью транспорта.

### 9.2. Записи

`handleRecording` сохраняет MangoCallRecording по recording_id и ставит [ProcessRecord](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Jobs/ProcessRecord.php) с задержкой в минуту. Job делает до 10 попыток с backoff 60, ждёт ActivityLog звонка, прикрепляет recording_id и ставит attached_at. Поиск ActivityLog использует entry_id и тип описания, не включает аккаунт АТС. Это потенциальная неоднозначность между аккаунтами.

[RecordController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/RecordController.php) строит подписанную ссылку и проксирует, перенаправляет или скачивает аудио. В api routes `record2/record3` объявлены вне auth-группы. Это подтверждённая структура маршрутов; фактическую доступность через production proxy отдельно не проверяли.

### 9.3. Calltracking и результаты сделок

[MangoCallTrackingController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoCallTrackingController.php) ищет последнюю изменённую заявку по телефону в салоне и дополняет пустые UTM, URL, устройство, город. При отсутствии заявки возвращает 404: если обогащение придёт раньше создания заявки, сам по себе этот код его не отложит.

[MangoDealUpdateService](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/MangoDealUpdateService.php) использует **другой API** — `widgets-api.../calltracking/{siteId}/deals`, `mango_site_id` и `mango_token`. Статусы маппятся в accepted/declined/closed; сумма в коде условная, а не реальная сумма сделки. Calltouch использует собственный endpoint выгрузки сделок и Access-Token/SiteId. Эти настройки не заменяют VPBX API key/salt.

**Предложение:** разделить подключения `Mango VPBX`, `Mango Calltracking`, `Calltouch` по возможностям. Провайдер может поддерживать получение, обогащение, исходящие команды и передачу конверсий — не обязательно всё сразу.

## 10. Приезд, кредит, продажа

### 10.1. Факты

В полной карточке `openArrive` требует дату приезда, собирает данные из заявки, в `arrival.phone_number` берёт последние четыре цифры телефона. [ArrivalController::saveArrival](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L55) сохраняет отдельную запись с менеджером, салоном, источником, фактом посещения, консультацией, флагами передачи и комментариями.

`copyArrival` отмечает посещение/forwarded и создаёт CreditRequest с копированием имени, телефона, менеджера, региона, салона и сайта. В этом методе нет связи новой записи с id исходной Arrival и защиты повторного выполнения. Проверяемые связи по id важнее совпадения текстовых полей.

[CreditRequest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CreditRequest.php) содержит отдельную колонку для каждого банка и отдельные поля менеджеров банков. `saveCreditRequest` записывает эти значения, флаги отказов, переходов и комментарии. `syncCreditRequest` клонирует запись с `parent_id` — отдельный сценарий связанной кредитной записи/созаёмщика.

`saleCreditRequest`: сохраняет дату продажи, банк, VIN текстом, сотрудников продажи и оформления, последующий контроль/перезвон/оценку, затем soft-delete кредитную запись. Следовательно, удалённая запись здесь может означать завершённую продажу, а не уничтожение данных.

Telegram-уведомления и цели местами завязаны на конкретные id салонов/получателей. Универсального движка передач между произвольными отделами в этой структуре нет.

### 10.2. Предложение V2

- Единый клиент и контакты; заявка/работа салона с явными связями.
- Приезд как связанная запись, а не потеря связи при копировании. Полный телефон хранится в контактах, последние цифры — только режим отображения.
- Передача как сущность: из какого отдела, в какой, кто передал, кто принял, когда, статус/причина. Повторное нажатие не создаёт вторую передачу.
- Кредитное дело и несколько заявок/решений банков. Новый банк — новая строка справочника, не миграция колонок.
- Решение: банк, состояние, одобренная сумма/срок/ставка/взнос при необходимости, комментарий, автор и время; история изменений.
- Продажа/результат — явное состояние и данные сделки. Soft delete не используется как способ пометить продажу.
- VIN и автомобиль пока могут быть текстовой потребностью; будущая связь со складом добавляется без фиктивной складской записи.

## 11. Дополнительные сценарии, которые нельзя потерять

| Сценарий                   | Старое поведение                                                                                                                                                                                                                        | Предложение                                                                                       |
| -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Копирование в салон        | [OrderController::copy](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L568) клонирует заявку, сбрасывает часть полей, подменяет сайт на фиксированные id | Связанная работа другого салона и журнал передачи; исходная атрибуция не подменяется              |
| Массовое назначение        | `passOrders` обновляет operator_id по переданным order_ids                                                                                                                                                                              | Проверка области каждого объекта и оператора, транзакция, аудит, конфликт/лимит пакета            |
| Отложенная покупка         | Клон в служебный салон, DeferredPurchase с датой возврата                                                                                                                                                                               | Состояние/задача повторной работы без фиктивного салона                                           |
| Возврат отложенных         | Команда и endpoint создают ещё клон с фиксированным сайтом                                                                                                                                                                              | Идемпотентное возобновление/новый цикл работы с сохранённой связью                                |
| Чёрный список              | Проверяет номер и regex префиксов, переносит в салон 16, пишет исходный салон в form_id                                                                                                                                                 | Отдельный признак карантина/качества + причина, исходный салон неизменен                          |
| Обратная передача партнёру | `Victory/PassAoController` выбирает статус 14, отправляет телефон/домен, отмечает PassedOrder                                                                                                                                           | Настраиваемая подписка исходящих событий, журнал доставки, идемпотентность                        |
| SMS                        | Шаблоны по салону, ручная отправка, SmsMessage и статус; запрос провайдера синхронный                                                                                                                                                   | Очередь, ограничения отправки, связь с клиентом/заявкой, статус доставки отдельно от принятия API |
| Экспорт агентству          | [ExportController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ExportController.php) выбирает заявки определённого agency_id по created_at                                 | Ограниченный ключ партнёра, фильтр его объектов, курсор обновлений и контракт статусов            |
| Реестр проектов            | Сайт/салон/тип/система/менеджер/статус/comment                                                                                                                                                                                          | Отдельный маркетинговый модуль; не смешивать с лидами и источниками                               |

## 12. Склад и логистика — изучены, реализация отложена

Новые авто ([Car](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Car.php)) и БУ ([UsedCar](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/UsedCar.php)) разделены. Новые: салон, марка/модель/год, двигатель/трансмиссия/привод, комплектация, цвет, VIN/кузов/ПТС/ключи, цены, поставщик, состояние продажи. БУ дополнительно содержат владельцев, пробег, покупку, расходы, подготовку, документы и массивы фото/видео. Есть несколько вариантов клиентских списков и журнал изменений.

[TransitController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php): инициирование перемещения ставит флаг автомобиля и создаёт Transit/UsedcarTransit. Подтверждение меняет салон автомобиля, записывает получателя, завершает перемещение. Общая транзакция и блокировка автомобиля в показанных методах не используются. Отмена — отдельные методы.

[ShipmentController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php): отправка с салонами, отправителем/получателем, водителем/компанией, ожидаемой датой, стоимостью, статусом и составом новых/БУ авто. Наличие controller API не гарантирует клиентский экран: router ссылается и на отсутствующие страницы.

Загрузка БУ-фото использует исходное имя файла и public-каталог. В V2 нужны контролируемые имена, тип/размер, права, безопасное хранение. Бронирование автомобиля в просмотренных серверных путях не установлено как целостный механизм; его надо проектировать отдельно и проверять гонку бронирований.

Рекомендация на будущее: единая сущность физического автомобиля, отдельные операции поступления, расходов, резерва, продажи и перемещения; новый/БУ как свойства/профили. Решение об объединении таблиц принимается на этапе склада, не сейчас.

## 13. Отчёты: полезное и проблемы модели

Три основных контроллера содержат SQL-агрегации по операторам, салонам, сайтам, агентствам, статусам, оплате, причинам, UTM и одобрениям. Есть Excel-экспорты и специальные отчёты партнёров. Разные даты: created_at для поступления, will_arrive для плана, arrived_date для факта, date/sale_date кредитной записи.

В SQL видны смысловые алиасы статусов: NewCount, OnProccessCount, NoAnswerCount, WillArriveCount, ArrivedCount и др. Это подтверждает назначение конкретных формул, но не заменяет реальные названия справочника БД. Отчёты используют исключения конкретных салонов/операторов и agency_id. Часть исключает retries, часть считает все строки. В `bySite` считаются только строки без retries; в `SummaryReport::all` есть INNER JOIN сайтов и агентств. Лиды без этих связей выпадают.

Фильтры некоторых SQL строятся конкатенацией request-параметров. Это требует исправления на параметры запроса и валидацию; безопасность production здесь не тестировалась. Нельзя копировать такие запросы в V2.

**Предложение:** прежде чем рисовать отчёт, утвердить словарь метрик: что считается, по какой дате, в какой области, как считаются повторные, что означает конверсия и какие обращения исключаются. Отдельно считать клиентов, обращения, заявки, звонки, назначения, приезды и сделки. Для конверсии по когорте поступления и для событий за день нужны разные показатели.

Атрибуция обращения сохраняется снимком. Изменение агентства/типа источника не должно молча менять прошлый отчёт. Текущего ответственного и сотрудника, совершившего действие, также нельзя смешивать.

## 14. Надёжность и доступ: подтверждённые зоны внимания

Это замечания к старой версии для проектирования V2. Они не означают, что новый проект содержит те же ошибки.

| Приоритет | Наблюдение кода                                                                                                                                                                                          | Последствие/предложение                                                                           |
| --------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| Высокий   | Несколько отчётов, CSV, record2/record3, sync-user и диагностические маршруты вне auth-группы                                                                                                            | Проверить и закрыть каждый маршрут; middleware guest не является проверкой подлинности интеграции |
| Высокий   | SQL с прямой подстановкой параметров                                                                                                                                                                     | Валидация + bound parameters; серверная область доступа до агрегации                              |
| Высокий   | [OrderCreated](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderCreated.php), MangoIncome, ClearNotify используют публичный Channel с данными клиента | Private channels и строгая авторизация, минимальный payload                                       |
| Высокий   | Общий ключ интеграций без явной области salon/source в ряде путей                                                                                                                                        | Ограниченные подключения и проверка пары идентификаторов                                          |
| Высокий   | Секреты видны в карточках/ответах, ошибки токенов отправляются в Telegram/логи                                                                                                                           | Шифрование, маскирование, allowlist сериализации и редактирование секретов отдельно               |
| Высокий   | Jivo возвращает HTTP 200 при исключении создания                                                                                                                                                         | Подтверждать получение после durable сохранения; последующую ошибку повторять внутри системы      |
| Высокий   | Pull watermark обновляется до обработки пакета                                                                                                                                                           | Потенциальный пропуск отдельных лидов; durable staging/checkpoint                                 |
| Высокий   | Разные locks Mango, синхронные эффекты, нет общего inbox                                                                                                                                                 | Конкурентная обработка событий и восстановление должны проектироваться явно                       |
| Средний   | Незагруппированные OR в части scopes/ProcessDistribute                                                                                                                                                   | Фильтр салона может не ограничивать все ветки; использовать сгруппированные условия               |
| Средний   | Минимальная нагрузка выбирается без атомарной фиксации                                                                                                                                                   | Неравномерная раздача при конкуренции; транзакционная очередь назначения                          |
| Средний   | Дублирование распределения и разных нормализаций телефона                                                                                                                                                | Разные результаты одного бизнес-сценария; общие сервисы и проверки                                |
| Средний   | `role_id`, `site_id`, `showroom_id`, причины/банки зависят от чисел/колонок                                                                                                                              | Справочники, семантические настройки, явные связи                                                 |
| Средний   | Copy/transfer создаёт независимые записи без единой связи                                                                                                                                                | Потеря происхождения и двойной учёт; связи и журнал событий                                       |
| Средний   | Физическое удаление сайта и повторное forceDelete авто                                                                                                                                                   | Разрушение истории; deactivate/archive и отдельные политики хранения                              |

[routes/channels.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/channels.php) сам по себе не делает публичный `Channel` приватным. Наличие защиты в одном endpoint не защищает остальные.

## 15. Предлагаемая модель V2: источники и справочники

Это архитектурное предложение на обсуждение, не готовые миграции и не разрешение реализации.

### 15.1. Разделение понятий

| Сущность                | Вопрос                                        | Пример                                                 |
| ----------------------- | --------------------------------------------- | ------------------------------------------------------ |
| Вид источника           | Что это за площадка?                          | Сайт, бот, соцсеть, классифайд, партнёр, ручной ввод   |
| Конкретный источник     | Откуда именно клиент?                         | Сайт салона А, бот подбора, кабинет VK салона Б        |
| Классификация источника | Как группировать маркетинг?                   | SEO, реклама, смешанный, VA — после уточнения смысла   |
| Канал обращения         | Каким способом обратился клиент?              | Запрос обратного звонка, прямой звонок, чат            |
| Цель обращения          | Что клиент хочет?                             | Консультация, кредит, бронь, trade-in                  |
| Агентство               | Кто отвечает за привлечение?                  | Собственная команда или подрядчик                      |
| Провайдер/адаптер       | Какой протокол интеграции?                    | Mango VPBX, Mango Calltracking, Calltouch, generic API |
| Подключение             | Какая конкретная учётная запись/конфигурация? | Аккаунт Mango центра, ключ бота, кабинет партнёра      |

Технический HTTP webhook — транспорт, а не канал клиента. Бот может прислать запрос обратного звонка; HTTP не должен подменять это значение в отчёте. Технический Mango callback и маркетинговое «заказать обратный звонок» тоже разные события.

### 15.2. Гибкость без бесконечной разработки

- Виды источников, классификации, агентства и допустимые сочетания ведутся в справочниках. Администратор может добавить нового поставщика лидов с общим API без правки логики.
- Для вида «Сайт» можно выбрать SEO/Реклама/Реклама+SEO. Для бота показываются подходящие классификации, либо поле необязательно. Допустимость задаётся связями справочников, проверяется сервером, а не скрытием поля.
- Источник имеет несколько каналов; канал фиксируется у каждого обращения. Нельзя выбирать один канал навсегда в карточке сайта.
- Справочник — данные. Адаптер — код протокола. Добавление строки «Новая телефония» не реализует авторизацию, подпись и структуру её API. Для нового протокола нужна отдельная разработка; для нового аккаунта уже поддержанного провайдера — только настройки.
- Дополнительные поля источника допускаются ограниченно: тип поля, подпись, обязательность, список значений. Не превращать ядро связей, права и секреты в произвольный EAV/JSON-конструктор.

### 15.3. Предлагаемые таблицы уровня концепции

| Группа          | Таблицы/назначение                                                                       | Ключевые ограничения                                                                               |
| --------------- | ---------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| Источники       | `source_kinds`, `source_categories`, их допустимые сочетания, `agencies`, `lead_sources` | Источник с названием и салоном, URL необязателен; справочники не содержат API-секретов             |
| Каналы          | `contact_channels`, допустимые каналы вида/источника                                     | Значение обращения валидируется; код канала не равен старому Order.source_id                       |
| Интеграции      | `integration_connections`, привязки источников/внешних проектов                          | Секреты зашифрованы, внешние id уникальны в нужной области провайдера/аккаунта                     |
| Линии           | `telephony_lines`, привязка source/connection, назначения внутренних номеров             | SIP хранится строкой; нормализация телефона отдельно; аккаунт входит в ключ сопоставления          |
| Клиенты         | `customers`, `customer_contacts`                                                         | Нормализованный контакт не обязан означать единственного человека: общий семейный телефон возможен |
| Работа салона   | `leads` или другое согласованное имя                                                     | Обязательный салон, клиент, статус, ответственный; разные салоны не сливаются автоматически        |
| Факты обращения | `inquiries`                                                                              | Каждое реальное обращение отдельно, источник/канал/время/атрибуция, связь с заявкой                |
| Звонки          | `calls`, `call_legs`, `call_recordings`                                                  | Аккаунт+entry, id плеча, направление, ответивший, исходящий запрос                                 |
| Доставка        | `integration_inbox`, `outbox`, delivery attempts, import cursors                         | Уникальность ключа события в пределах подключения; история попыток и ошибок                        |
| История         | comments, status transitions, assignments, transfers, visits                             | Автор, момент, прежнее/новое; просмотр согласно области доступа                                    |
| Кредит          | banks, credit cases/applications, decisions                                              | Каждый банк/попытка отдельной записью, не новой колонкой                                           |

Названия предварительные. В новой модели не стоит одновременно использовать `sources` и `order_sources` для разных смыслов без чётких имён. Старое `site_id` может оставаться входным alias совместимости; его mapping не переносит старую БД и не должен совпадать с id провайдера.

### 15.4. Как выглядит создание источника

1. Название, салон, вид: «Сайт», «Бот», «VK», «Партнёр» и т.д.
2. Подходящая классификация из справочника; агентство по необходимости.
3. URL/идентификаторы площадки только если применимы.
4. Разрешённые каналы: например, сайт — обратный звонок, прямой звонок, чат.
5. Блок «Подключения»: форма/API, телефония, аналитика. Несколько подключений допустимы.
6. В Mango VPBX выбирается аккаунт и привязываются линия/SIP. В Mango Calltracking — внешний Site ID и токен. В Calltouch — соответствующие параметры проекта. Пользователю не требуется понимать внутреннее разбиение таблиц.
7. Активность, права, аудит; проверка подключения только когда реализован соответствующий адаптер. Сохранённые настройки не обозначаются как «Интеграция работает» без проверки.

## 16. Общий путь обработки V2

1. Приём внешнего сообщения адаптером, проверка подписи/ключа/области и размера.
2. Нормализация конверта: подключение, external event id, event type, occurred_at, received_at, клиентские данные, внешние ссылки. Сохранение в inbox. Успешное подтверждение провайдеру — по его протоколу после надёжного сохранения.
3. Повторная доставка того же события не создаёт новое обращение. Порядок событий не предполагается гарантированным.
4. Сопоставление источник/салон. Пара showroom_id/source_id проверяется по области подключения; значения не принимаются на веру. Нераспознанное, но аутентифицированное сообщение сохраняется для разбора, без случайного fallback в другой салон. Неаутентифицированное отклоняется.
5. Нормализация контакта и поиск кандидата клиента. Неуверенные совпадения не сливаются молча.
6. Создание факта обращения. Связывание с работой нужного салона по принятому правилу повторов. Уведомление о другом салоне не открывает сотруднику чужую карточку без права.
7. Вычисление расчётной даты по настройке салона/явному переопределению. Снимок атрибуции и правила сохраняется.
8. Назначение оператора общей политикой, либо реальный ответивший по АТС. События ringing/connected/summary обновляют один звонок, а не создают три лида.
9. Сохранение бизнес-изменений и outbox в одной транзакции. Рассылка UI/аналитике/уведомлениям после commit; сбой отправки не откатывает уже принятый лид.
10. Повторные попытки с ограниченным backoff, статусом ошибки, ручным повтором с аудитом и контролем дублей. Для pull — продвижение курсора только после надёжного приёма.

Предлагаемые очереди: критические события телефонии, приём/нормализация лидов, распределение, исходящие интеграции, записи звонков, уведомления/отчёты. Число очередей и workers выбирается по фактической задержке и нагрузке; 500 обращений/день не требует микросервисов. Laravel-приложение с модулями, MySQL и Redis достаточно как исходная архитектура. Redis не должен быть единственным местом хранения факта принятия лида.

Гарантия «ровно один раз по всей сети» не обещается. Практический контракт — повторяемая доставка, идемпотентные эффекты, восстановление из сохранённых событий, мониторинг задержек/ошибок и сверка с провайдером при наличии API истории.

## 17. Что сохранить, изменить и не переносить

| Решение                  | Содержание                                                                                                                                            |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Сохранить                | Операторский рабочий сценарий, полную потребность, графики/нагрузку, историю, повторы, popup, ответившего, записи, приезд, кредит, выгрузки           |
| Сделать настройками      | Границу суток, обслуживаемые салоны, участие каналов/классификаций в нагрузке, сохранение ответственного, соответствия внешних id и конверсий         |
| Нормализовать            | Источник/канал/цель/способ оплаты/результат, клиент/обращение/заявка, банки/решения, передача/копия                                                   |
| Не переносить            | Магические id, служебные салоны для спама и отложенных покупок, секреты в таблицах UI, public broadcast персональных данных, прямую подстановку в SQL |
| Не делать преждевременно | Микросервисы, универсальный исполняемый конструктор правил, все провайдеры сразу, переделку дизайна, чат и склад до разрешённого этапа                |

## 18. Рекомендуемая последовательность дальнейшей работы

Это план обсуждения и разработки, а не команда автономного выполнения.

1. Принять терминологию и исправить PROJECT_SPEC/ROADMAP на основе этого аудита. Зафиксировать источники, каналы, подключения, обращения и работу салона.
2. Проверить уже сделанный справочник сайтов, если Cursor успел его реализовать: сначала карта отличий и безопасное расширение без потери данных, не удаление и не создание второго параллельного справочника.
3. Один этап «Источники и справочники»: виды, подходящие классификации, агентства, карточка источника, каналы; параметры подключений с честным статусом «настройка сохранена». Не выдавать CRUD ключей за работающую интеграцию.
4. Отдельно граница дня салона и проверяемый калькулятор периода; согласовать маркировку дат и исключения по типу.
5. Клиенты, обращения, заявки, история и ручная работа. Согласовать повторы прежде окончательной схемы и отчётов.
6. Графики/назначения с конкуренцией и журналом причин.
7. Inbox/outbox и общий вход для одного простого внешнего отправителя, затем Mango VPBX как полный сквозной сценарий; Calltracking/конверсии отдельно.
8. Приезды, передачи, кредитные решения и итог работы.
9. Метрики/отчёты по утверждённым определениям, эксплуатация и восстановление.
10. Чат и склад отдельными последующими заданиями.

Каждый этап заканчивается проверками и ручной приёмкой. Не начинать следующую часть только потому, что она указана здесь.

## 19. Вопросы, которые действительно требуют бизнес-ответа

1. Одна активная работа клиента на салон или несколько параллельных потребностей? Закрытая заявка открывается снова или создаётся новая? Это не вопрос количества фактических обращений — они сохраняются всегда.
2. Какое значение показывать в «Повтор N»: все обращения либо дополнительные после первого? Рекомендуется «Обращений 4 · Повторных 3» в подробностях.
3. Одному общему источнику требуется распределение между салонами или достаточно отдельного источника для каждого? Если общий поток нужен, правила маршрутизации должны сохраняться, а не исчезать при упрощении V2.
4. Что именно означает VA в бизнесе? Не требовать ответа для сохранения исторического названия, но не назначать ему семантику без подтверждения.
5. К какому дню относится вечерний период и нужны ли ещё границы для типов источников поверх салонной настройки?
6. Что означает «нагрузка»: число новых лидов, активных работ, назначений за смену или взвешенные обращения? Старый счётчик — только один из вариантов.
7. Какие конкретно поставщики обязательны в первом запуске и какие реальные примеры сообщений они отправляют? Названия JS AI/ДРОМ АСП/АРМАНД из требований нельзя автоматически сопоставить каждому Import-контроллеру.
8. Какие старые специализированные экраны/отчёты фактически используются? Имеющийся код содержит дубли и шаблонные фрагменты.

## 20. Ограничения и следующий уровень проверки

Чтобы получить точную схему БД, нужны DDL основных таблиц. В этой проверке SQL-файл пользователя повторно не получен и не прочитан; результаты прежних обсуждений не заменяют его текущего содержимого. Нужны только обезличенные значения используемых справочников и схемы — персональная история клиентов для проектирования не нужна.

Чтобы подтвердить runtime: отдельная тестовая среда старой CRM с согласованными данными, реальные обезличенные payload от обязательных поставщиков, конфигурация расписаний/queues и исходники используемого Mango-пакета на точной версии. Без этого статический анализ нельзя объявлять E2E-проверкой вебхуков, телефонии, прав или подсчётов.

Ни одна функция V2 не реализована этим документом. Рекомендации по схеме, интерфейсу источника и последовательности — предложения для утверждения.

## 21. Дополнительные результаты сверки связности

### 21.1. Объявления маршрутов без соответствующего метода

После автоматического поиска вручную сверены следующие активные объявления в [routes/api.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php):

- `GET test-orders` указывает на отсутствующий [OrderController::test](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php).
- `POST arrivals/to-arrive` и `POST arrivals/to-credit` указывают на отсутствующие [ArrivalController::to_arrive/to_credit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php).
- `POST fares/upload` указывает на отсутствующий [FareController::upload](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/FareController.php).
- `POST report/agency/target-website-webhook` указывает на отсутствующий [ReportController::bySitesWebhook](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php).

Методы не найдены в соответствующих классах; базовый Controller не содержит этих бизнес-обработчиков. Это статически подтверждённые несоответствия данной версии. Они не доказывают, что пользователи реально вызывают эти пути: рядом есть другие рабочие методы, а runtime не запускался. Закомментированные маршруты с отсутствующими методами в этот список не включены.

В [client/router.js](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/router.js) есть ссылки на отсутствующие страницы `showroom/index.vue`, `showroom/_id.vue`, `shipment/index.vue`, `shipment/edit.vue`, `car/car-logs.vue`, `usedcar/used-logs.vue`. Есть похожие страницы в других каталогах, но это не делает указанные пути корректными.

[ArrivalController::saveArrival](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L55) вызывает `sendNotify/sendNotifyOperator` в отдельных ветках. Определения этих методов в ArrivalController и базовом Controller не найдены. Следовательно, сам факт существования флагов передачи ещё не подтверждает работоспособность этих веток.

### 21.2. Сервис проверки автомобиля

[DataAvController](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DataAvController.php) содержит два серверных запроса к внешнему сервису по госномеру: получение информации и постановка отчёта в очередь провайдера. В исходнике встроен credential; его значение намеренно не включено в документ. Для V2 это отдельное подключение с секретом вне репозитория, ограничением прав и журналом вызовов; нельзя автоматически считать эту функцию обязательной для первого релиза.

### 21.3. Качество автоматических проверок старого проекта

Есть базовые ExampleTest, unit-тесты MangoCallData и ApiBenchmarkTest. Последний проверяет лишь верхнюю границу времени запроса `test-orders`, не проверяет успешность ответа и целостность бизнес-результата. Это не достаточная проверка работающего API. Наличие тестовых файлов нельзя использовать как доказательство корректности распределения, очередей, доступов и отчётов.

### 21.4. Безопасность публичной формы

Секрет сервера интеграции нельзя помещать в JavaScript сайта и считать надёжной авторизацией. В V2 серверные отправители используют секретные credentials; браузерный виджет/форма — отдельный публичный идентификатор, проверки допустимого источника, лимиты и противодействие спаму. Проверка Origin полезна, но сама по себе не заменяет аутентификацию серверного отправителя.

## Приложения: индекс проверяемого репозитория

Следующие реестры сформированы автоматически по исходникам указанного commit. Они обеспечивают охват файлов и точные переходы к реализации. Автоматическая индексация не является доказательством корректности поведения или использования метода в production.

### А. Все серверные контроллеры и методы

Индекс включает и исторические/диагностические методы. Ссылка на метод не доказывает регистрацию маршрута.

**[app/Http/Controllers/ActivityController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ActivityController.php)**
[car_activities](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ActivityController.php#L14); [used_activities](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ActivityController.php#L20)

**[app/Http/Controllers/Admin/CarController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php)**
[marks](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php#L17); [models](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php#L22); [saveMark](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php#L28); [updateMark](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php#L37); [deleteMark](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php#L46); [saveModel](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php#L57); [updateModel](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php#L67); [deleteModel](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Admin/CarController.php#L77)

**[app/Http/Controllers/ArrivalController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L25); [arrivals](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L30); [arrival_consultations](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L40); [all_arrivals](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L48); [saveArrival](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L55); [saveConsultation](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L108); [deleteArrival](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L158); [deleteTrashArrivals](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L168); [copyArrival](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ArrivalController.php#L176)

**[app/Http/Controllers/Auth/PermissionController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php)**
[abilities](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L21); [retract](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L28); [disallow](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L34); [forbid](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L44); [create](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L52); [allow](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L62); [sync](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L69); [users](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L99); [user_permissions](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L105); [allAbilities](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L112); [userPermissionRows](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L122); [saveUserPermissions](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/PermissionController.php#L133)

**[app/Http/Controllers/Auth/RoleController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/RoleController.php)**
[__invoke](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/RoleController.php#L18); [operator_roles](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Auth/RoleController.php#L27)

**[app/Http/Controllers/AuthController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php)**
[login](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php#L17); [logout](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php#L36); [me](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php#L43); [impersonate](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php#L64); [stopImpersonation](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php#L91); [inSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php#L114); [clearCache](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthController.php#L162)

**[app/Http/Controllers/AuthLogController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthLogController.php)**
[logs](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AuthLogController.php#L13)

**[app/Http/Controllers/AutospotController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AutospotController.php)**
[create](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/AutospotController.php#L21)

**[app/Http/Controllers/BlacklistController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/BlacklistController.php)**
[blacklists](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/BlacklistController.php#L16); [save](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/BlacklistController.php#L29); [update](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/BlacklistController.php#L51); [delete](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/BlacklistController.php#L66)

**[app/Http/Controllers/CarController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarController.php)**
[cars](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarController.php#L18); [save](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarController.php#L24); [delete](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarController.php#L58); [test](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarController.php#L68)

**[app/Http/Controllers/CarPropertyController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php)**
[carmodels](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L19); [allModels](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L26); [carMarks](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L34); [bodytypes](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L42); [enginetypes](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L49); [transmissions](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L56); [colors](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L63); [wheeltypes](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L70); [statuses](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CarPropertyController.php#L78)

**[app/Http/Controllers/Controller.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Controller.php)**
Нет собственных именованных методов.

**[app/Http/Controllers/CreditNotifyController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditNotifyController.php)**
[sendNotify](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditNotifyController.php#L16); [notifyManager](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditNotifyController.php#L30)

**[app/Http/Controllers/CreditRequestController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L26); [creditRequests](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L33); [creditAllRequests](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L51); [sales](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L62); [saveCreditRequest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L70); [saleCreditRequest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L157); [deleteCreditRequests](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L204); [deleteSoldCreditRequests](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L231); [syncCreditRequest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L251); [deleteTrashCreditRequests](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L267); [sendNotify](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L275); [sendNotifyOperator](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L285); [banks](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L295); [showrooms](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L301); [restore](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CreditRequestController.php#L307)

**[app/Http/Controllers/Crm/CrmImportOrdersController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L37); [parseSpreadsheet](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L68); [importRows](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L92); [importRow](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L139); [createOrder](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L161); [resolveSiteId](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L179); [isHeaderRow](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L190); [resolveColumns](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L207); [cell](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L234); [formatPhone](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Crm/CrmImportOrdersController.php#L244)

**[app/Http/Controllers/CrmController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CrmController.php)**
[setWorkPlace](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/CrmController.php#L10)

**[app/Http/Controllers/DashboardController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DashboardController.php)**
[index](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DashboardController.php#L20); [orders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DashboardController.php#L28); [creditRequests](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DashboardController.php#L63); [used_cars](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DashboardController.php#L72); [cars](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DashboardController.php#L80); [car_sales](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DashboardController.php#L88)

**[app/Http/Controllers/DataAvController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DataAvController.php)**
[report](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DataAvController.php#L10); [number](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/DataAvController.php#L33)

**[app/Http/Controllers/ExportController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ExportController.php)**
[getOrderData](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ExportController.php#L13)

**[app/Http/Controllers/FareController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/FareController.php)**
[fares](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/FareController.php#L15); [save](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/FareController.php#L21); [create](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/FareController.php#L36); [delete](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/FareController.php#L81); [addFare](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/FareController.php#L88)

**[app/Http/Controllers/GravitelController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/GravitelController.php)**
[events](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/GravitelController.php#L25); [call](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/GravitelController.php#L90); [sms](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/GravitelController.php#L115); [getContent](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/GravitelController.php#L123); [decoded](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/GravitelController.php#L161); [clearNotify](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/GravitelController.php#L192)

**[app/Http/Controllers/Import/ImportAllPVController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportAllPVController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportAllPVController.php#L29); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportAllPVController.php#L33)

**[app/Http/Controllers/Import/ImportCsvController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportCsvController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportCsvController.php#L15); [formatPhone](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportCsvController.php#L59)

**[app/Http/Controllers/Import/ImportJustweController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportJustweController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportJustweController.php#L23)

**[app/Http/Controllers/Import/ImportJustwePvController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportJustwePvController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportJustwePvController.php#L23)

**[app/Http/Controllers/Import/ImportVLLController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLController.php#L20)

**[app/Http/Controllers/Import/ImportVLLKrasnodar2Controller.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLKrasnodar2Controller.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLKrasnodar2Controller.php#L27); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLKrasnodar2Controller.php#L33); [getNext](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLKrasnodar2Controller.php#L176)

**[app/Http/Controllers/Import/ImportVLLKrasnodarController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLKrasnodarController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLKrasnodarController.php#L27); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLKrasnodarController.php#L33); [getNext](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVLLKrasnodarController.php#L171)

**[app/Http/Controllers/Import/ImportVaController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVaController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Import/ImportVaController.php#L27)

**[app/Http/Controllers/ImportAgencyController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAgencyController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAgencyController.php#L26); [test](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAgencyController.php#L122)

**[app/Http/Controllers/ImportAuraController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAuraController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAuraController.php#L27); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAuraController.php#L32); [distribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAuraController.php#L133)

**[app/Http/Controllers/ImportAutodomController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAutodomController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAutodomController.php#L24); [distribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAutodomController.php#L158)

**[app/Http/Controllers/ImportAutopoleController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAutopoleController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAutopoleController.php#L25); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAutopoleController.php#L30); [distribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAutopoleController.php#L130)

**[app/Http/Controllers/ImportAvangardController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAvangardController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAvangardController.php#L28); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAvangardController.php#L33); [distribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportAvangardController.php#L134)

**[app/Http/Controllers/ImportInHousePVController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportInHousePVController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportInHousePVController.php#L25); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportInHousePVController.php#L30)

**[app/Http/Controllers/ImportJustweController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweController.php#L18); [handle](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweController.php#L106); [distribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweController.php#L167); [checkUserSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweController.php#L244)

**[app/Http/Controllers/ImportJustweVdlController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweVdlController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweVdlController.php#L25); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweVdlController.php#L31); [getNext](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportJustweVdlController.php#L117)

**[app/Http/Controllers/ImportRequestController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportRequestController.php)**
[importByDate](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportRequestController.php#L25); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportRequestController.php#L119); [import_today](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportRequestController.php#L255)

**[app/Http/Controllers/ImportVdlController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlController.php)**
[import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlController.php#L29)

**[app/Http/Controllers/ImportVdlKrasnodar2Controller.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlKrasnodar2Controller.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlKrasnodar2Controller.php#L26); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlKrasnodar2Controller.php#L32); [getNext](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlKrasnodar2Controller.php#L161)

**[app/Http/Controllers/ImportVdlKrasnodarController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlKrasnodarController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlKrasnodarController.php#L26); [import](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlKrasnodarController.php#L32); [getNext](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ImportVdlKrasnodarController.php#L157)

**[app/Http/Controllers/JivoWebhookController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/JivoWebhookController.php)**
[handle](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/JivoWebhookController.php#L18)

**[app/Http/Controllers/LeadController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L26); [lead](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L32); [leadDrive](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L84); [create](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L169); [createAutospot](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L222); [pairDistribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L276); [createVictory](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L385); [getShowroom](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L415); [getSite](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L421); [leadFromVkAds](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L428); [leadVk](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L494); [leadTarget](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L580); [vkDistributeRostov](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L658); [vkDistributeKrim](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L753); [getNext](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L848); [getNextShowroomId](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/LeadController.php#L853)

**[app/Http/Controllers/MangoCallTrackingController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoCallTrackingController.php)**
[execute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoCallTrackingController.php#L12)

**[app/Http/Controllers/MangoCommandController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoCommandController.php)**
[makeCall](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoCommandController.php#L11)

**[app/Http/Controllers/MangoController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php)**
[calling](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php#L23); [call](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php#L58); [checkPhone2](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php#L66); [checkPhone](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php#L122); [sms](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php#L171); [getContent](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php#L179); [decoded](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php#L217); [clearNotify](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoController.php#L248)

**[app/Http/Controllers/MangoWebhookController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php)**
[__construct](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php#L14); [call](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php#L19); [summary](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php#L26); [recordAdded](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php#L33); [process](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php#L40); [log](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/MangoWebhookController.php#L77)

**[app/Http/Controllers/OperatorController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php)**
[schedules](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php#L12); [programmaticSchedules](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php#L24); [updateSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php#L33); [updateProgrammaticSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php#L52); [createSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php#L71); [createProgrammaticSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php#L87); [deleteSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php#L104); [deleteProgrammaticSchedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OperatorController.php#L114)

**[app/Http/Controllers/OrderController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php)**
[orders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L51); [light_orders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L83); [all_orders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L167); [stat_orders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L175); [all_arrivals](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L190); [missed_calls](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L205); [detail](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L214); [histories](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L236); [types](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L267); [statuses](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L276); [trashes](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L285); [drops](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L296); [arrival_statuses](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L306); [repeats](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L316); [all_repeats](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L335); [saveOrder](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L350); [update](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L394); [distribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L533); [copy](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L568); [deferPurchase](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L625); [deleteDeferred](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L665); [passOrders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L678); [update_mini](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L700); [visited_mini](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L768); [delete](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L783); [deleteTrashOrders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L794); [create](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L802); [shedule](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L818); [check_phone](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L850); [blacklist](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L860); [blacklistRequest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L884); [export](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L897); [exportArrive](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L907); [deffered_orders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L918); [inhouseExport](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L1003); [returnPurchased](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderController.php#L1041)

**[app/Http/Controllers/OrderCountController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderCountController.php)**
[index](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/OrderCountController.php#L14)

**[app/Http/Controllers/PageController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/PageController.php)**
[getTabs](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/PageController.php#L11); [pages](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/PageController.php#L22)

**[app/Http/Controllers/ProjectController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProjectController.php)**
[projects](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProjectController.php#L21); [managers](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProjectController.php#L29); [statuses](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProjectController.php#L35); [types](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProjectController.php#L41); [systems](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProjectController.php#L54); [storeOrUpdate](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProjectController.php#L72)

**[app/Http/Controllers/ProstorSmsController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProstorSmsController.php)**
[sendSms](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ProstorSmsController.php#L11)

**[app/Http/Controllers/QuizController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/QuizController.php)**
[quiz](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/QuizController.php#L14)

**[app/Http/Controllers/RecordController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/RecordController.php)**
[getAudio](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/RecordController.php#L12); [getAudio2](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/RecordController.php#L29); [getAudio3](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/RecordController.php#L40)

**[app/Http/Controllers/ReportController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php)**
[rest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L22); [byOperator](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L29); [bySite](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L102); [bySiteAgency](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L138); [byAgency](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L195); [byStatus](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L239); [byTrash](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L277); [bySites](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L307); [payment](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L356); [byDates](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L393); [byContent](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L412); [byCampaign](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L455); [bySource](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L498); [byMedium](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L541); [byTerm](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L583); [byUtm](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L625); [extend](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L656); [approval](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L700); [drops](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ReportController.php#L755)

**[app/Http/Controllers/ShipmentController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php)**
[shipments](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L19); [companies](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L26); [detail](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L31); [drivers](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L36); [statuses](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L41); [save](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L47); [addCar](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L69); [addUsedCar](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L77); [delete](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShipmentController.php#L86)

**[app/Http/Controllers/ShowroomController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php)**
[managers](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L24); [second_managers](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L35); [preparers](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L45); [operators](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L55); [showrooms](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L63); [showroom](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L72); [sites](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L78); [site_types](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L85); [admin_sites](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L94); [saveSite](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L104); [updateSite](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L134); [duplicateSite](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L170); [deleteSite](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L189); [banks](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L205); [regions](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L215); [agencies](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L224); [saveOperator](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L234); [resetPasswords](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L245); [work_places](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/ShowroomController.php#L321)

**[app/Http/Controllers/SmsTemplateController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SmsTemplateController.php)**
[index](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SmsTemplateController.php#L12); [store](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SmsTemplateController.php#L31); [update](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SmsTemplateController.php#L53); [destroy](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SmsTemplateController.php#L69)

**[app/Http/Controllers/SummaryReportController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php)**
[all](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L11); [arrivals](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L64); [byOperator](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L89); [old](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L142); [drops](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L194); [victory](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L232); [victory_status](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L270); [byOperators](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L309); [agencySites](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/SummaryReportController.php#L379)

**[app/Http/Controllers/TargetController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TargetController.php)**
[leadTarget](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TargetController.php#L19)

**[app/Http/Controllers/TestController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php)**
[testrest](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L26); [distribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L147); [test_ext](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L220); [test_caltouch_orders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L236); [test_phone4](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L251); [read](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L287); [getEntry](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L367); [test_orders](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L381); [exportOrdersToExcel](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L405); [dilo](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L479); [search](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L492); [parsePhone](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L572); [testPattern](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TestController.php#L584)

**[app/Http/Controllers/TildaController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TildaController.php)**
[lead](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TildaController.php#L19); [create](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TildaController.php#L64); [createLead](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TildaController.php#L119); [createDistribute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TildaController.php#L234)

**[app/Http/Controllers/TransitController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php)**
[transits](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php#L18); [usedcar_transits](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php#L25); [transit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php#L33); [cancelTransit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php#L47); [approveTransit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php#L56); [usedTransit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php#L72); [cancelUsedTransit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php#L88); [approveUsedTransit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/TransitController.php#L98)

**[app/Http/Controllers/UdpautoController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UdpautoController.php)**
[lead](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UdpautoController.php#L16)

**[app/Http/Controllers/UsedCarController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php)**
[__invoke](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L23); [cars](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L33); [light_cars](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L39); [getSold](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L47); [list](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L52); [getUsedCar](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L72); [save](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L77); [ready](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L170); [savePurchase](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L183); [saveStorage](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L202); [delete](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L225); [transit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L238); [cancelTransit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L254); [approveTransit](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L263); [transits](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L277); [statuses](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L281); [upload](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L287); [deletePhoto](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L293); [histories](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UsedCarController.php#L306)

**[app/Http/Controllers/UserController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php)**
[users](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php#L20); [usersByShowroom](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php#L25); [sessions](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php#L32); [getUser](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php#L39); [update](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php#L45); [save](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php#L66); [setOnline](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php#L93); [roles](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/UserController.php#L101)

**[app/Http/Controllers/Victory/PassAoController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Victory/PassAoController.php)**
[execute](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/Victory/PassAoController.php#L28)

**[app/Http/Controllers/VictoryReportController.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php)**
[byOperator](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L20); [byOperatorPv](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L73); [bySite](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L127); [byAgency](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L151); [byStatus](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L176); [byTrash](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L216); [bySites](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L246); [byDates](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L283); [byContent](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L302); [byCampaign](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L343); [bySource](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L384); [byMedium](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L425); [byTerm](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L466); [byUtm](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L507); [extend](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L539); [operator](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Controllers/VictoryReportController.php#L580)

### Б. Все модели: поля и связи

Поля ниже взяты из `$fillable`, а не из схемы БД. Если список отсутствует, модель может всё равно использовать колонки через прямое присваивание. Связи определены по вызовам Eloquent; их внешние ключи нужно сверять с DDL.

**[app/Models/ActivityLog.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ActivityLog.php)**

- fillable: не объявлен в этом файле.
- связи: user, record.

**[app/Models/ActualProject.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ActualProject.php)**

- fillable: `site_id`, `site_id`, `showroom_id`, `type_id`, `manager_id`, `system_id`, `status_id`, `comment`.
- связи: showroom, site, type, status, manager, system.

**[app/Models/Agency.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Agency.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/AgencyType.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/AgencyType.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Arrival.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Arrival.php)**

- fillable: не объявлен в этом файле.
- связи: region, mark, manager, operator, showroom, site.

**[app/Models/AuthLog.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/AuthLog.php)**

- fillable: не объявлен в этом файле.
- связи: user.

**[app/Models/Bank.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Bank.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Blacklist.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Blacklist.php)**

- fillable: `phone`, `showroom_id`, `comment`.
- связи: showroom.

**[app/Models/BlacklistOrder.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/BlacklistOrder.php)**

- fillable: `order_id`, `reason`, `status_id`.
- связи: order.

**[app/Models/BodyType.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/BodyType.php)**

- fillable: не объявлен в этом файле.
- связи: usedcar.

**[app/Models/Brand.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Brand.php)**

- fillable: не объявлен в этом файле.
- связи: cars, models, carprice, usedcar, creditrequest, reports.

**[app/Models/CallCount.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CallCount.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/CallHistory.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CallHistory.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/CallRecord.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CallRecord.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/CalltouchOrder.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CalltouchOrder.php)**

- fillable: `order_id`, `status`, `sent_at`.
- связи: не обнаружены в собственных методах.

**[app/Models/CalltouchUpdateDeal.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CalltouchUpdateDeal.php)**

- fillable: `order_id`, `status_id`.
- связи: не обнаружены в собственных методах.

**[app/Models/Car.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Car.php)**

- fillable: не объявлен в этом файле.
- связи: complectation, mark, model, bodytype, transmission, color, enginetype, wheeltype, showroom, transits, status.

**[app/Models/CarModel.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CarModel.php)**

- fillable: `name`, `pic`.
- связи: brand, car, model, reports.

**[app/Models/Color.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Color.php)**

- fillable: не объявлен в этом файле.
- связи: usedcar.

**[app/Models/Company.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Company.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Competitors.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Competitors.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Complectation.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Complectation.php)**

- fillable: не объявлен в этом файле.
- связи: car.

**[app/Models/CreditRequest.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CreditRequest.php)**

- fillable: `id`, `date`, `client_name`, `region_id`, `live_region`, `mark_id`, `model`, `showroom_id`, `site_id`, `phone_number`, `price`, `initial_fee`, `manager_id`, `manager2_id`, `operator_id`, `comments`, `sd_comment`, `absolute`, `expo`, `sovkom`, `oranzh`, `tinkoff`, `kvant`, `zenit`, `loco_bank`, `cetelem`, `vtb`, `keb`, `rnb`, `uralsib`, `primsots`, `otp_bank`, `alfa_bank`, `pochta_bank`, `bistro_bank`, `atb`, `absolute_manager`, `expo_manager`, `sovkom_manager`, `oranzh_manager`, `tinkoff_manager`, `kvant_manager`, `zenit_manager`, `loco_manager`, `cetelem_manager`, `vtb_manager`, `keb_manager`, `rnb_manager`, `uralsib_manager`, `primsots_manager`, `otp_bank_manager`, `alfa_bank_manager`, `atb_manager`, `pochta_bank_manager`, `bistro_bank_manager`, `otkritie`, `otkritie_manager`, `canceled`, `client_canceled`, `fssp_canceled`, `jump`, `transit`, `transit_confirmed`, `car_name`, `sale_date`, `sale_bank_id`, `sale_vin`, `is_sold`, `is_tradein`, `sale_manager_id`, `sale_creditor_id`, `sale_oformitel_id`, `sale_source`, `sale_repeat`, `is_sale`, `source_credit`, `pictures`, `pictures2`, `videos`, `created_at`, `updated_at`, `deleted_at`, `sale_call_date`, `sale_recall_date`, `sale_vote_id`, `sale_control`, `sale_comment`, `co_applicant`, `co_applicant_name`, `parent_id`.
- связи: region, region_live, mark, operator, manager, manager2, sale_manager, sale_creditor, oformitel, showroom, bank, site, parent, childs.

**[app/Models/CustomActivity.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/CustomActivity.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/DeferredPurchase.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/DeferredPurchase.php)**

- fillable: `order_id`, `return_date`, `returned`.
- связи: не обнаружены в собственных методах.

**[app/Models/Dilo.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Dilo.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Driver.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Driver.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/EngineType.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/EngineType.php)**

- fillable: не объявлен в этом файле.
- связи: usedcar.

**[app/Models/Fare.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Fare.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/JustweBrand.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/JustweBrand.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/JustweModel.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/JustweModel.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/LastQueue.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/LastQueue.php)**

- fillable: `site_id`, `number`, `percent`.
- связи: не обнаружены в собственных методах.

**[app/Models/LastRequest.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/LastRequest.php)**

- fillable: не объявлен в этом файле.
- связи: site.

**[app/Models/Log.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Log.php)**

- fillable: не объявлен в этом файле.
- связи: user.

**[app/Models/Manager.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Manager.php)**

- fillable: не объявлен в этом файле.
- связи: usedcar, creditrequest.

**[app/Models/MangoCall.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/MangoCall.php)**

- fillable: `mango_account_id`, `entry_id`, `showroom_id`, `order_id`, `site_id`, `operator_id`, `direction`, `client_phone`, `line_number`, `operator_extension`, `status`, `entry_result`, `disconnect_reason`, `create_time`, `talk_time`, `end_time`, `call_sequences`, `popup_sent`, `summary_processed`, `payload`.
- связи: order, site, operator, recordings.

**[app/Models/MangoCallRecording.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/MangoCallRecording.php)**

- fillable: `mango_call_id`, `mango_account_id`, `entry_id`, `recording_id`, `user_id`, `recorded_at`, `attached_at`, `payload`.
- связи: call.

**[app/Models/MissedCall.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/MissedCall.php)**

- fillable: не объявлен в этом файле.
- связи: order.

**[app/Models/Operator.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Operator.php)**

- fillable: не объявлен в этом файле.
- связи: creditrequest, showroom, arrivals, clients, orders.

**[app/Models/OperatorSchedule.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OperatorSchedule.php)**

- fillable: `user_id`, `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`, `comment`.
- связи: operator.

**[app/Models/Order.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Order.php)**

- fillable: `id`, `client_name`, `phone`, `phone_2`, `phone_3`, `work_phone`, `callback`, `type_id`, `mark_id`, `model_id`, `comment`, `general_comment`, `operator_id`, `birthday`, `car_year`, `country`, `region_id`, `price`, `complectation`, `initial_fee`, `credit_period`, `month_pay`, `showroom_id`, `payment_method`, `last_call`, `will_arrive`, `arrived_date`, `arrived`, `status_id`, `site_id`, `date_of_sale`, `call_heard`, `commercial_offer`, `approved`, `canceled`, `link_1`, `link_2`, `trash_id`, `drop_id`, `arrival_id`, `call_heard`, `official_income`, `supouse_name`.
- связи: activityLogs, passedOrder, showroom, site, type, status, missed_call, operator, region, mark, source, model, trash, arrival_status, blacklist, deferredPurchases, deferPurchase.

**[app/Models/OrderArrival.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OrderArrival.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/OrderDrop.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OrderDrop.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/OrderHistory.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OrderHistory.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/OrderSource.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OrderSource.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/OrderStatus.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OrderStatus.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/OrderTrash.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OrderTrash.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/OrderType.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/OrderType.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Page.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Page.php)**

- fillable: не объявлен в этом файле.
- связи: children.

**[app/Models/PassedOrder.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/PassedOrder.php)**

- fillable: `order_id`.
- связи: order.

**[app/Models/Permission.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Permission.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Phone.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Phone.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/PhoneCode.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/PhoneCode.php)**

- fillable: не объявлен в этом файле.
- связи: region, operator.

**[app/Models/PhoneOperator.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/PhoneOperator.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/PhoneRegion.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/PhoneRegion.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Preparer.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Preparer.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/ProgrammaticSchedule.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ProgrammaticSchedule.php)**

- fillable: `user_id`, `monday`, `tuesday`, `wednesday`, `thursday`, `friday`, `saturday`, `sunday`, `comment`.
- связи: operator.

**[app/Models/ProjectManager.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ProjectManager.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/ProjectStatus.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ProjectStatus.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/ProjectSystem.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ProjectSystem.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/ProjectType.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ProjectType.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Region.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Region.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Role.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Role.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Shipment.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Shipment.php)**

- fillable: не объявлен в этом файле.
- связи: from_showroom, showroom, driver, sender, receiver, status, detail, cars, used_cars.

**[app/Models/ShipmentCar.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ShipmentCar.php)**

- fillable: не объявлен в этом файле.
- связи: car, shipment.

**[app/Models/ShipmentDetail.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ShipmentDetail.php)**

- fillable: не объявлен в этом файле.
- связи: shipment, car, used_car.

**[app/Models/ShipmentStatus.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ShipmentStatus.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/ShipmentUsedCar.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/ShipmentUsedCar.php)**

- fillable: не объявлен в этом файле.
- связи: car, shipment.

**[app/Models/Showroom.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Showroom.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Site.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Site.php)**

- fillable: `title`, `phone`, `second_phone`, `url`, `pic`, `description`, `showroom_id`, `to_showroom`, `type_id`, `agency_id`, `manager_id`, `active`, `actual`, `order`, `token`, `post_url`, `import`, `calltouch_access_key`, `calltouch_site_id`, `mango_site_id`, `mango_token`, `sip`.
- связи: showroom, agency, request.

**[app/Models/SiteType.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/SiteType.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/SmsMessage.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/SmsMessage.php)**

- fillable: `showroom_id`, `phone`, `text`, `sender`, `schedule_time_utc`, `status_queue_name`, `provider_message_id`, `status`, `error_code`, `error_message`, `provider_response`, `sent_at`, `delivered_at`.
- связи: не обнаружены в собственных методах.

**[app/Models/SmsTemplate.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/SmsTemplate.php)**

- fillable: `showroom_id`, `operator_id`, `name`, `body`, `is_active`.
- связи: showroom.

**[app/Models/Status.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Status.php)**

- fillable: не объявлен в этом файле.
- связи: usedcar.

**[app/Models/Test.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Test.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

**[app/Models/Transit.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Transit.php)**

- fillable: не объявлен в этом файле.
- связи: car, showroom, sender, receiver, from_showroom.

**[app/Models/Transmission.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/Transmission.php)**

- fillable: не объявлен в этом файле.
- связи: usedcar.

**[app/Models/UsedCar.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/UsedCar.php)**

- fillable: `auto_ru`, `showroom_id`, `income_date`, `car_name`, `mark_id`, `model_id`, `year`, `bodytype_id`, `transmission_id`, `enginetype_id`, `wheeltype_id`, `owner_count`, `power`, `salon`, `color`, `milage`, `volume`, `vin_number`, `number`, `sts`, `ptc`, `income_price`, `price`, `price_avito`, `key_number`, `status_id`, `registered`, `transit`, `manager`, `comment_purchase`, `comment`, `pictures`, `pictures2`, `videos`, `expose`, `sale_autoru`, `is_ready`, `manager_id`, `is_tradein`, `deleted_at`, `diagnostic_before`, `diagnostic_after`, `dry_cleaning`, `polish`, `painting`, `repair_price`, `painting_price`, `transport_price`, `preparation_price`, `frontal_price`, `milage_price`, `removal_price`, `user_id`, `repair`, `entity`, `sale_repeat`, `repair_body`, `repair_painting`, `repair_locksmith`, `repair_electric`, `repair_others`, `repair_dry`, `repair_polishing`, `repair_milage`, `repair_windshield`, `body_status`, `painting_status`, `locksmith_status`, `electric_status`, `others_status`, `dry_cleaning_status`, `polishing_status`, `milage_status`, `windshield_status`, `preparation_status`, `body_comment`, `painting_comment`, `locksmith_comment`, `electric_comment`, `others_comment`, `dry_cleaning_comment`, `polishing_comment`, `millage_comment`, `windshield_comment`, `preparation_comment`.
- связи: mark, model, bodytype, transmission, color, enginetype, wheeltype, manager, showroom, transits, status, user.

**[app/Models/UsedcarTransit.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/UsedcarTransit.php)**

- fillable: не объявлен в этом файле.
- связи: usedcar, showroom, sender, receiver, from_showroom.

**[app/Models/User.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/User.php)**

- fillable: `first_name`, `last_name`, `role_id`, `showroom_id`, `work_place`, `active`, `email`, `password`.
- связи: roles, role, showroom, orders, schedule, operatorSchedule, programmaticSchedule.

**[app/Models/WheelType.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/WheelType.php)**

- fillable: не объявлен в этом файле.
- связи: usedcar.

**[app/Models/WorkPlace.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Models/WorkPlace.php)**

- fillable: не объявлен в этом файле.
- связи: не обнаружены в собственных методах.

### В. Сервисы, listeners, jobs, события, команды и экспорты

**app/Services/**

- [app/Services/BlacklistService.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/BlacklistService.php): checkPhoneInBlacklistOrSpam, updateOrderWithBlacklist
- [app/Services/CalltouchDealUpdateService.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/CalltouchDealUpdateService.php): update, buildPayload
- [app/Services/DistributeService.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/DistributeService.php): distribute
- [app/Services/Mango/MangoCallData.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/Mango/MangoCallData.php): directionFromRealtime, isOutboundApiCall, isCallback, clientPhone, operatorExtension, lineNumber, externalPhone, extensionValue
- [app/Services/Mango/MangoCallService.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/Mango/MangoCallService.php): __construct, handleRealtime, handleSummary, handleRecording, handleIncomingRealtime, createIncomingOrder, findOrder, resolveOperator, phoneInfo, popupPayload, upsertHistory, resolveShowroomId, isSip, payloadToArray
- [app/Services/Mango/MangoSiteResolver.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/Mango/MangoSiteResolver.php): __construct, resolve
- [app/Services/MangoDealUpdateService.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/MangoDealUpdateService.php): update
- [app/Services/OrderDistributeService.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/OrderDistributeService.php): distribute, checkUserSchedule
- [app/Services/ProstorSmsService.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Services/ProstorSmsService.php): send

**app/Listeners/**

- [app/Listeners/MangoIncomeEventListener.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Listeners/MangoIncomeEventListener.php): __construct, handle
- [app/Listeners/OrderAddedEventListener.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Listeners/OrderAddedEventListener.php): __construct, handle, distribute, teleBot, checkUserSchedule
- [app/Listeners/OrderAddedExcelEventListener.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Listeners/OrderAddedExcelEventListener.php): handle, distribute, checkUserSchedule
- [app/Listeners/OrderAddedPvEventListener.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Listeners/OrderAddedPvEventListener.php): __construct, handle, distribute, teleBot, checkUserSchedule
- [app/Listeners/OrderEventListener.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Listeners/OrderEventListener.php): __construct, handle

**app/Jobs/**

- [app/Jobs/CallTouchDealUpdate.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Jobs/CallTouchDealUpdate.php): __construct, handle
- [app/Jobs/DealUpdate.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Jobs/DealUpdate.php): __construct, handle
- [app/Jobs/ProcessCall.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Jobs/ProcessCall.php): __construct, handle
- [app/Jobs/ProcessDistribute.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Jobs/ProcessDistribute.php): __construct, handle
- [app/Jobs/ProcessRecord.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Jobs/ProcessRecord.php): __construct, handle, failed

**app/Events/**

- [app/Events/ArrivalCreated.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/ArrivalCreated.php): __construct, broadcastOn
- [app/Events/CarAdded.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/CarAdded.php): __construct, broadcastOn
- [app/Events/ClearNotify.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/ClearNotify.php): __construct, broadcastOn
- [app/Events/ClientProcessed.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/ClientProcessed.php): __construct, broadcastOn
- [app/Events/ConsultationProceed.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/ConsultationProceed.php): __construct, broadcastOn
- [app/Events/CreditCallProcessed.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/CreditCallProcessed.php): __construct, broadcastOn
- [app/Events/CreditCreated.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/CreditCreated.php): __construct, broadcastOn
- [app/Events/MangoIncome.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/MangoIncome.php): __construct, broadcastOn
- [app/Events/NotificationSend.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/NotificationSend.php): __construct, broadcastOn
- [app/Events/OrderCreated.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderCreated.php): __construct, broadcastOn
- [app/Events/OrderExcelCreated.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderExcelCreated.php): __construct, broadcastOn
- [app/Events/OrderProcessed.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderProcessed.php): __construct, broadcastOn
- [app/Events/OrderPvCreated.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/OrderPvCreated.php): __construct, broadcastOn
- [app/Events/SaleCreditProcessed.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/SaleCreditProcessed.php): __construct, broadcastOn
- [app/Events/UsedCarAdded.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Events/UsedCarAdded.php): __construct, broadcastOn

**app/Console/Commands/**

- [app/Console/Commands/ImportAgency.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportAgency.php): handle — команда `import-agency:import`
- [app/Console/Commands/ImportAllPV.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportAllPV.php): handle — команда `pv-all:import`
- [app/Console/Commands/ImportAura.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportAura.php): handle — команда `vp-aura:import`
- [app/Console/Commands/ImportAutodom.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportAutodom.php): handle — команда `vp-autodom:import`
- [app/Console/Commands/ImportAutopole.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportAutopole.php): handle — команда `vp-autopole:import`
- [app/Console/Commands/ImportAvangard.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportAvangard.php): handle — команда `vp-avangard:import`
- [app/Console/Commands/ImportInHousePV.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportInHousePV.php): handle — команда `pv-inhouse:import`
- [app/Console/Commands/ImportJustwe.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportJustwe.php): handle — команда `import:justwe`
- [app/Console/Commands/ImportJustweAPlus.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportJustweAPlus.php): handle — команда `import:justwe-ap`
- [app/Console/Commands/ImportJustwePV.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportJustwePV.php): handle — команда `justwe-pv:import`
- [app/Console/Commands/ImportJustweVDL.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportJustweVDL.php): handle — команда `justwe-vdl:import`
- [app/Console/Commands/ImportRequest.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportRequest.php): __construct, handle — команда `request-vdl:import`
- [app/Console/Commands/ImportVA.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportVA.php): __construct, handle — команда `request-va:import`
- [app/Console/Commands/ImportVDL.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportVDL.php): handle — команда `import-vdl:import`
- [app/Console/Commands/ImportVDLKrasnodar.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportVDLKrasnodar.php): handle — команда `import-krasnodar-vdl:import`
- [app/Console/Commands/ImportVDLKrasnodar2.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportVDLKrasnodar2.php): handle — команда `import-krasnodar2-vdl:import`
- [app/Console/Commands/ImportVLL.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportVLL.php): handle — команда `import-all-vll:import`
- [app/Console/Commands/ImportVLLKrasnodar.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportVLLKrasnodar.php): handle — команда `import-krasnodar-vll:import`
- [app/Console/Commands/ImportVLLKrasnodar2.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ImportVLLKrasnodar2.php): handle — команда `import-krasnodar-vll2:import`
- [app/Console/Commands/MangoDealOrders.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/MangoDealOrders.php): __construct, handle — команда `mango:deal-orders`
- [app/Console/Commands/OrderPassAo.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/OrderPassAo.php): __construct, handle — команда `pass-ao:orders`
- [app/Console/Commands/ReturnDeferredPurchases.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/ReturnDeferredPurchases.php): handle — команда `purchases:return-deferred`
- [app/Console/Commands/SendCalltouchOrders.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Console/Commands/SendCalltouchOrders.php): __construct, handle — команда `calltouch:send`

**app/Exports/**

- [app/Exports/ArriveExport.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Exports/ArriveExport.php): columnWidths, map, headings, columnFormats, __construct, collection
- [app/Exports/InhouseExport.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Exports/InhouseExport.php): __construct, collection, map, headings, columnWidths, columnFormats
- [app/Exports/NoAnswerExport.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Exports/NoAnswerExport.php): columnWidths, map, headings, columnFormats, __construct, collection

**app/Notifications/**

- [app/Notifications/CreditApprove.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Notifications/CreditApprove.php): __construct, via, toTelegram, toArray
- [app/Notifications/NotifyApproveManager.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Notifications/NotifyApproveManager.php): __construct, via, toTelegram, toArray
- [app/Notifications/NotifyCancelOperator.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Notifications/NotifyCancelOperator.php): __construct, via, toTelegram, toArray
- [app/Notifications/NotifyConsultationOperator.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Notifications/NotifyConsultationOperator.php): __construct, via, toTelegram, toArray
- [app/Notifications/ResetPassword.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Notifications/ResetPassword.php): toMail, resetUrl
- [app/Notifications/VerifyEmail.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Notifications/VerifyEmail.php): verificationUrl

**app/Http/Middleware/**

- [app/Http/Middleware/ApiToken.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/ApiToken.php): handle
- [app/Http/Middleware/ApiToken2.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/ApiToken2.php): handle
- [app/Http/Middleware/ApiTokenAutospot.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/ApiTokenAutospot.php): handle
- [app/Http/Middleware/ApiTokenTilda.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/ApiTokenTilda.php): handle
- [app/Http/Middleware/Authenticate.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/Authenticate.php): redirectTo
- [app/Http/Middleware/EncryptCookies.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/EncryptCookies.php)
- [app/Http/Middleware/PreventRequestsDuringMaintenance.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/PreventRequestsDuringMaintenance.php)
- [app/Http/Middleware/RedirectIfAuthenticated.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/RedirectIfAuthenticated.php): handle
- [app/Http/Middleware/TrimStrings.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/TrimStrings.php)
- [app/Http/Middleware/TrustHosts.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/TrustHosts.php): hosts
- [app/Http/Middleware/TrustProxies.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/TrustProxies.php)
- [app/Http/Middleware/TrustedAccess.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/TrustedAccess.php): __construct, handle, isHostnameMatch
- [app/Http/Middleware/VerifyCsrfToken.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/app/Http/Middleware/VerifyCsrfToken.php)

### Г. Объявления HTTP-маршрутов

Это статический индекс объявлений по файлам. Он не заменяет `route:list`: включает объявления в комментариях, не вычисляет префиксы и наследуемые middleware, не подтверждает отсутствие дубликатов и доступность endpoint. Безопасность выше проверялась чтением окружающих route groups.

| Файл/строка                                                                                                                   | Метод  | Шаблон                                         | Обработчик                                                                       |
| ----------------------------------------------------------------------------------------------------------------------------- | ------ | ---------------------------------------------- | -------------------------------------------------------------------------------- |
| [routes/api.php:69](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L69)   | GET    | `/test-showroom/{id}/{key}`                    | `function ($id, $key) {`                                                         |
| [routes/api.php:83](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L83)   | GET    | `parse-phone`                                  | `[TestController::class, 'parsePhone'])`                                         |
| [routes/api.php:84](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L84)   | GET    | `test-pattern`                                 | `[TestController::class, 'testPattern'])`                                        |
| [routes/api.php:85](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L85)   | POST   | `csv-import`                                   | `[ImportCsvController::class, 'import'])`                                        |
| [routes/api.php:87](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L87)   | POST   | `report/agency/sites`                          | `[ReportController::class, 'bySites']);`                                         |
| [routes/api.php:95](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L95)   | GET    | `operators`                                    | `[ShowroomController::class, 'operators'])`                                      |
| [routes/api.php:96](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L96)   | GET    | `orders_open`                                  | `[OrderController::class, 'orders'])`                                            |
| [routes/api.php:98](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L98)   | GET    | `orders/all/{id}`                              | `[OrderController::class, 'all_orders'])`                                        |
| [routes/api.php:99](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L99)   | GET    | `orders/stats/{id}`                            | `[OrderController::class, 'stat_orders'])`                                       |
| [routes/api.php:100](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L100) | GET    | `test_search`                                  | `[TestController::class, 'search'])`                                             |
| [routes/api.php:102](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L102) | GET    | `/sales`                                       | `[CreditRequestController::class, 'sales'])`                                     |
| [routes/api.php:103](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L103) | GET    | `/blacklists`                                  | `[BlacklistController::class, 'blacklists'])`                                    |
| [routes/api.php:105](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L105) | POST   | `blacklists/save`                              | `[BlacklistController::class, 'save'])`                                          |
| [routes/api.php:106](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L106) | POST   | `blacklists/update`                            | `[BlacklistController::class, 'update'])`                                        |
| [routes/api.php:107](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L107) | POST   | `blacklists/delete`                            | `[BlacklistController::class, 'delete'])`                                        |
| [routes/api.php:111](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L111) | POST   | `report/victory/operator`                      | `[VictoryReportController::class, 'operator'])`                                  |
| [routes/api.php:112](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L112) | POST   | `report/operator/victory`                      | `[VictoryReportController::class, 'byOperator'])`                                |
| [routes/api.php:113](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L113) | POST   | `report/operator/all-pv`                       | `[VictoryReportController::class, 'byOperatorPv'])`                              |
| [routes/api.php:114](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L114) | POST   | `report/status/victory`                        | `[VictoryReportController::class, 'byStatus'])`                                  |
| [routes/api.php:116](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L116) | POST   | `report/utm`                                   | `[ReportController::class, 'byUtm']);`                                           |
| [routes/api.php:118](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L118) | POST   | `export/arrive`                                | `[OrderController::class, 'exportArrive']);`                                     |
| [routes/api.php:119](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L119) | POST   | `export/no-answer`                             | `[OrderController::class, 'export']);`                                           |
| [routes/api.php:120](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L120) | POST   | `report/extend`                                | `[ReportController::class, 'extend']);`                                          |
| [routes/api.php:121](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L121) | GET    | `orders/missed-calls/{id}`                     | `[OrderController::class, 'missed_calls'])`                                      |
| [routes/api.php:122](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L122) | GET    | `import_test`                                  | `[ImportRequestController::class, 'import_test']);`                              |
| [routes/api.php:123](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L123) | GET    | `temp`                                         | `[TestController::class, 'read']);`                                              |
| [routes/api.php:124](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L124) | GET    | `get-entry`                                    | `[TestController::class, 'getEntry']);`                                          |
| [routes/api.php:125](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L125) | GET    | `rest`                                         | `[OrderCountController::class, 'index'])`                                        |
| [routes/api.php:126](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L126) | POST   | `/check_user`                                  | `[MangoEventController::class, 'testrest']);`                                    |
| [routes/api.php:127](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L127) | GET    | `/test-distribute/{showroom_id}`               | `[TestController::class, 'distribute']);`                                        |
| [routes/api.php:128](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L128) | POST   | `/test-ext`                                    | `[TestController::class, 'test_ext']);`                                          |
| [routes/api.php:129](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L129) | GET    | `/test-phone4`                                 | `[TestController::class, 'test_phone4']);`                                       |
| [routes/api.php:130](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L130) | GET    | `/test-calltouch`                              | `[TestController::class, 'test_caltouch_orders']);`                              |
| [routes/api.php:132](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L132) | GET    | `arrivals`                                     | `[ArrivalController::class, 'arrivals'])`                                        |
| [routes/api.php:133](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L133) | POST   | `arrivals/copy`                                | `[ArrivalController::class, 'copyArrival'])`                                     |
| [routes/api.php:136](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L136) | POST   | `report/approval`                              | `[ReportController::class, 'approval'])`                                         |
| [routes/api.php:139](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L139) | POST   | `report/operator`                              | `[ReportController::class, 'byOperator'])`                                       |
| [routes/api.php:140](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L140) | POST   | `reports/by-operator`                          | `[SummaryReportController::class, 'byOperators'])`                               |
| [routes/api.php:142](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L142) | GET    | `credit-requests`                              | `[CreditRequestController::class, 'creditRequests'])`                            |
| [routes/api.php:144](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L144) | GET    | `test-orders`                                  | `[OrderController::class, 'test'])`                                              |
| [routes/api.php:147](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L147) | GET    | `user-activities/logs`                         | `[AuthLogController::class, 'logs'])`                                            |
| [routes/api.php:150](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L150) | POST   | `reports/all`                                  | `[SummaryReportController::class, 'all'])`                                       |
| [routes/api.php:151](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L151) | POST   | `reports/operator`                             | `[SummaryReportController::class, 'byOperator'])`                                |
| [routes/api.php:152](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L152) | POST   | `reports/arrivals`                             | `[SummaryReportController::class, 'arrivals'])`                                  |
| [routes/api.php:153](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L153) | POST   | `reports/drops`                                | `[SummaryReportController::class, 'drops'])`                                     |
| [routes/api.php:154](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L154) | POST   | `reports/victory`                              | `[SummaryReportController::class, 'victory'])`                                   |
| [routes/api.php:155](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L155) | POST   | `reports/agency-sites`                         | `[SummaryReportController::class, 'agencySites'])`                               |
| [routes/api.php:159](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L159) | GET    | `/orders/export-inhouse`                       | `[OrderController::class, 'inhouseExport'])`                                     |
| [routes/api.php:161](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L161) | GET    | `projects`                                     | `[ProjectController::class, 'projects'])`                                        |
| [routes/api.php:162](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L162) | GET    | `project/managers`                             | `[ProjectController::class, 'managers'])`                                        |
| [routes/api.php:163](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L163) | GET    | `project/types`                                | `[ProjectController::class, 'types'])`                                           |
| [routes/api.php:164](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L164) | GET    | `project/systems`                              | `[ProjectController::class, 'systems'])`                                         |
| [routes/api.php:165](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L165) | GET    | `project/statuses`                             | `[ProjectController::class, 'statuses'])`                                        |
| [routes/api.php:166](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L166) | POST   | `projects/save`                                | `[ProjectController::class, 'storeOrUpdate'])`                                   |
| [routes/api.php:172](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L172) | GET    | `/import-order`                                | `[ImportRequestController::class, 'importByDate']);`                             |
| [routes/api.php:173](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L173) | GET    | `/import-vdl`                                  | `[ImportVdlController::class, 'import']);`                                       |
| [routes/api.php:174](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L174) | GET    | `test-ao`                                      | `[PassAoController::class, 'execute'])`                                          |
| [routes/api.php:176](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L176) | POST   | `/broadcasting/auth`                           | `function (Illuminate\Http\Request $req) {`                                      |
| [routes/api.php:186](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L186) | GET    | `/sanctum/csrf-cookie`                         | `[CsrfCookieController::class, 'show'])->middleware('web');`                     |
| [routes/api.php:187](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L187) | POST   | `/login`                                       | `[AuthController::class, 'login'])->middleware('web');`                          |
| [routes/api.php:188](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L188) | POST   | `sync-user`                                    | `[PermissionController::class, 'sync']);`                                        |
| [routes/api.php:189](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L189) | GET    | `check_phone`                                  | `[OrderController::class, 'check_phone']);`                                      |
| [routes/api.php:191](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L191) | GET    | `/record2/{id}/{recording_id}`                 | `[RecordController::class, 'getAudio2']);`                                       |
| [routes/api.php:192](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L192) | GET    | `/record3/{id}/{recording_id}`                 | `[RecordController::class, 'getAudio3']);`                                       |
| [routes/api.php:193](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L193) | POST   | `/lead-albato/vk/create`                       | `[LeadController::class, 'leadVk']);`                                            |
| [routes/api.php:194](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L194) | POST   | `/lead-albato/{showroom_id}/create`            | `[LeadController::class, 'leadFromVkAds']);`                                     |
| [routes/api.php:195](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L195) | POST   | `/lead-albato/{showroom_id}/rostov-distribute` | `[LeadController::class, 'vkDistributeRostov']);`                                |
| [routes/api.php:196](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L196) | POST   | `/lead-albato/{showroom_id}/krim-distribute`   | `[LeadController::class, 'vkDistributeKrim']);`                                  |
| [routes/api.php:198](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L198) | POST   | `lead/autospot/create`                         | `[AutospotController::class, 'create'])->middleware('api_token_autospot')`       |
| [routes/api.php:202](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L202) | POST   | `lead/create`                                  | `[LeadController::class, 'create'])`                                             |
| [routes/api.php:203](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L203) | POST   | `lead/pair/distribute`                         | `[LeadController::class, 'pairDistribute'])`                                     |
| [routes/api.php:205](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L205) | POST   | `lead/drive/create`                            | `[LeadController::class, 'leadDrive'])`                                          |
| [routes/api.php:211](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L211) | POST   | `/tilda`                                       | `[TildaController::class, 'lead'])`                                              |
| [routes/api.php:221](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L221) | POST   | `/udpauto`                                     | `[UdpautoController::class, 'lead'])`                                            |
| [routes/api.php:226](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L226) | POST   | `lead/victory/create`                          | `[LeadController::class, 'createVictory'])`                                      |
| [routes/api.php:229](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L229) | POST   | `lead/target/create`                           | `[TargetController::class, 'leadTarget'])`                                       |
| [routes/api.php:231](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L231) | POST   | `lead/{id}/create`                             | `[LeadController::class, 'lead'])`                                               |
| [routes/api.php:234](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L234) | POST   | `fares/create`                                 | `[FareController::class, 'create'])`                                             |
| [routes/api.php:238](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L238) | GET    | `export/orders`                                | `[ExportController::class, 'getOrderData']);`                                    |
| [routes/api.php:242](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L242) | GET    | `/record/{id}/{recording_id}`                  | `[RecordController::class, 'getAudio']);`                                        |
| [routes/api.php:244](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L244) | POST   | `/users/reset-passwords`                       | `[ShowroomController::class, 'resetPasswords']);`                                |
| [routes/api.php:246](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L246) | GET    | `/user`                                        | `[AuthController::class, 'me']);`                                                |
| [routes/api.php:247](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L247) | POST   | `/impersonate/{id}`                            | `[AuthController::class, 'impersonate'])->whereNumber('id')->middleware('web');` |
| [routes/api.php:248](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L248) | POST   | `/impersonate/leave`                           | `[AuthController::class, 'stopImpersonation'])->middleware('web');`              |
| [routes/api.php:249](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L249) | POST   | `/logout`                                      | `[AuthController::class, 'logout'])->middleware('web');`                         |
| [routes/api.php:250](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L250) | GET    | `/users`                                       | `[UserController::class, 'users']);`                                             |
| [routes/api.php:251](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L251) | GET    | `/user-roles`                                  | `[UserController::class, 'roles']);`                                             |
| [routes/api.php:252](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L252) | POST   | `/user/update`                                 | `[UserController::class, 'update']);`                                            |
| [routes/api.php:253](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L253) | POST   | `/user/save`                                   | `[UserController::class, 'save']);`                                              |
| [routes/api.php:254](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L254) | GET    | `/users/by-showroom`                           | `[UserController::class, 'usersByShowroom']);`                                   |
| [routes/api.php:255](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L255) | POST   | `/user/change-online`                          | `[UserController::class, 'setOnline']);`                                         |
| [routes/api.php:256](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L256) | GET    | `/get-user/{id}`                               | `[UserController::class, 'getUser']);`                                           |
| [routes/api.php:258](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L258) | GET    | `/activities`                                  | `[ActivityController::class, 'car_activities']);`                                |
| [routes/api.php:259](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L259) | GET    | `/car-activities`                              | `[ActivityController::class, 'car_activities']);`                                |
| [routes/api.php:260](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L260) | GET    | `/used-activities`                             | `[ActivityController::class, 'used_activities']);`                               |
| [routes/api.php:263](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L263) | GET    | `/cars`                                        | `[CarController::class, 'cars']);`                                               |
| [routes/api.php:264](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L264) | GET    | `/models`                                      | `[CarPropertyController::class, 'allModels']);`                                  |
| [routes/api.php:265](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L265) | GET    | `/models/{mark_id}`                            | `[CarPropertyController::class, 'carmodels']);`                                  |
| [routes/api.php:266](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L266) | POST   | `/car/save`                                    | `[CarController::class, 'save']);`                                               |
| [routes/api.php:267](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L267) | GET    | `/car/delete/{id}`                             | `[CarController::class, 'delete']);`                                             |
| [routes/api.php:268](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L268) | GET    | `/enginetypes`                                 | `[CarPropertyController::class, 'enginetypes']);`                                |
| [routes/api.php:269](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L269) | GET    | `/bodytypes`                                   | `[CarPropertyController::class, 'bodytypes']);`                                  |
| [routes/api.php:270](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L270) | GET    | `/marks`                                       | `[CarPropertyController::class, 'carMarks']);`                                   |
| [routes/api.php:271](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L271) | GET    | `/admin/marks`                                 | `[AdminCarController::class, 'marks']);`                                         |
| [routes/api.php:272](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L272) | POST   | `/admin/marks/save`                            | `[AdminCarController::class, 'saveMark']);`                                      |
| [routes/api.php:273](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L273) | POST   | `/admin/marks/update`                          | `[AdminCarController::class, 'updateMark']);`                                    |
| [routes/api.php:274](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L274) | POST   | `/admin/marks/delete`                          | `[AdminCarController::class, 'deleteMark']);`                                    |
| [routes/api.php:275](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L275) | GET    | `/admin/models`                                | `[AdminCarController::class, 'models']);`                                        |
| [routes/api.php:276](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L276) | POST   | `/admin/models/save`                           | `[AdminCarController::class, 'saveModel']);`                                     |
| [routes/api.php:277](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L277) | POST   | `/admin/models/update`                         | `[AdminCarController::class, 'updateModel']);`                                   |
| [routes/api.php:278](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L278) | POST   | `/admin/models/delete`                         | `[AdminCarController::class, 'deleteModel']);`                                   |
| [routes/api.php:280](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L280) | POST   | `/admin/clear-cache`                           | `[AuthController::class, 'clearCache']);`                                        |
| [routes/api.php:282](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L282) | GET    | `/transmissions`                               | `[CarPropertyController::class, 'transmissions']);`                              |
| [routes/api.php:283](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L283) | GET    | `/wheeltypes`                                  | `[CarPropertyController::class, 'wheeltypes']);`                                 |
| [routes/api.php:284](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L284) | GET    | `/statuses`                                    | `[CarPropertyController::class, 'statuses']);`                                   |
| [routes/api.php:285](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L285) | GET    | `/showrooms`                                   | `[ShowroomController::class, 'showrooms']);`                                     |
| [routes/api.php:286](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L286) | GET    | `/showroom/{id}`                               | `[ShowroomController::class, 'showroom']);`                                      |
| [routes/api.php:289](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L289) | POST   | `/car/transit`                                 | `[TransitController::class, 'transit']);`                                        |
| [routes/api.php:290](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L290) | POST   | `/car/transit/approve`                         | `[TransitController::class, 'approveTransit']);`                                 |
| [routes/api.php:291](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L291) | POST   | `/car/transit/cancel`                          | `[TransitController::class, 'cancelTransit']);`                                  |
| [routes/api.php:292](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L292) | GET    | `/car/transits`                                | `[TransitController::class, 'transits']);`                                       |
| [routes/api.php:297](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L297) | GET    | `/managers`                                    | `[ShowroomController::class, 'managers']);`                                      |
| [routes/api.php:298](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L298) | GET    | `/managers2`                                   | `[ShowroomController::class, 'second_managers']);`                               |
| [routes/api.php:301](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L301) | POST   | `/operator/save`                               | `[ShowroomController::class, 'saveOperator']);`                                  |
| [routes/api.php:303](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L303) | GET    | `/work-places`                                 | `[ShowroomController::class, 'work_places']);`                                   |
| [routes/api.php:307](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L307) | GET    | `/used-cars`                                   | `[UsedCarController::class, 'cars']);`                                           |
| [routes/api.php:310](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L310) | POST   | `/used-car/transit`                            | `[TransitController::class, 'usedTransit']);`                                    |
| [routes/api.php:311](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L311) | POST   | `/used-car/transit/approve`                    | `[TransitController::class, 'approveUsedTransit']);`                             |
| [routes/api.php:312](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L312) | POST   | `/used-car/transit/cancel`                     | `[TransitController::class, 'cancelUsedTransit']);`                              |
| [routes/api.php:313](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L313) | GET    | `/used-car/transits`                           | `[TransitController::class, 'usedcar_transits']);`                               |
| [routes/api.php:314](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L314) | POST   | `/used-car/histories`                          | `[UsedCarController::class, 'histories']);`                                      |
| [routes/api.php:317](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L317) | POST   | `/used-car/save`                               | `[UsedCarController::class, 'save']);`                                           |
| [routes/api.php:318](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L318) | POST   | `/used-car/delete`                             | `[UsedCarController::class, 'delete']);`                                         |
| [routes/api.php:322](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L322) | GET    | `/car/shipments`                               | `[ShipmentController::class, 'shipments']); //;`                                 |
| [routes/api.php:323](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L323) | GET    | `/shipment/companies`                          | `[ShipmentController::class, 'companies']);`                                     |
| [routes/api.php:324](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L324) | GET    | `/shipment/drivers`                            | `[ShipmentController::class, 'drivers']);`                                       |
| [routes/api.php:325](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L325) | GET    | `/shipment/statuses`                           | `[ShipmentController::class, 'statuses']);`                                      |
| [routes/api.php:326](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L326) | POST   | `/shipment/save`                               | `[ShipmentController::class, 'save']);`                                          |
| [routes/api.php:327](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L327) | GET    | `/shipment/statuses`                           | `[ShipmentController::class, 'statuses']);`                                      |
| [routes/api.php:328](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L328) | GET    | `/shipment/detail/{id}`                        | `[ShipmentController::class, 'detail']); //;`                                    |
| [routes/api.php:329](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L329) | GET    | `/shipment/delete/{id}`                        | `[ShipmentController::class, 'delete']); //;`                                    |
| [routes/api.php:330](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L330) | POST   | `/shipment/car/add`                            | `[ShipmentController::class, 'addCar']); //;`                                    |
| [routes/api.php:331](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L331) | POST   | `/shipment/used-car/add`                       | `[ShipmentController::class, 'addUsedCar']); //;`                                |
| [routes/api.php:336](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L336) | GET    | `arrivals/all`                                 | `[ArrivalController::class, 'all_arrivals'])`                                    |
| [routes/api.php:337](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L337) | POST   | `arrivals/save`                                | `[ArrivalController::class, 'saveArrival'])`                                     |
| [routes/api.php:338](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L338) | POST   | `arrivals/to-arrive`                           | `[ArrivalController::class, 'to_arrive'])`                                       |
| [routes/api.php:339](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L339) | POST   | `arrivals/to-credit`                           | `[ArrivalController::class, 'to_credit'])`                                       |
| [routes/api.php:340](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L340) | GET    | `arrivals/{id}`                                | `[ArrivalController::class, 'arrivals'])`                                        |
| [routes/api.php:343](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L343) | GET    | `orders`                                       | `[OrderController::class, 'orders'])`                                            |
| [routes/api.php:344](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L344) | GET    | `deffered-orders`                              | `[OrderController::class, 'deffered_orders'])`                                   |
| [routes/api.php:346](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L346) | GET    | `light-orders`                                 | `[OrderController::class, 'light_orders'])`                                      |
| [routes/api.php:348](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L348) | POST   | `order/pass-orders`                            | `[OrderController::class, 'passOrders'])`                                        |
| [routes/api.php:350](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L350) | GET    | `orders/arrivals/{id}`                         | `[OrderController::class, 'all_arrivals'])`                                      |
| [routes/api.php:352](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L352) | GET    | `testrest`                                     | `[OrderController::class, 'findModel'])`                                         |
| [routes/api.php:353](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L353) | POST   | `orders/save`                                  | `[OrderController::class, 'saveOrder'])`                                         |
| [routes/api.php:354](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L354) | POST   | `orders/update`                                | `[OrderController::class, 'update'])`                                            |
| [routes/api.php:355](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L355) | POST   | `orders/copy`                                  | `[OrderController::class, 'copy'])`                                              |
| [routes/api.php:356](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L356) | POST   | `orders/defer-purchase`                        | `[OrderController::class, 'deferPurchase'])`                                     |
| [routes/api.php:357](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L357) | POST   | `orders/delete-deferred-item`                  | `[OrderController::class, 'deleteDeferred'])`                                    |
| [routes/api.php:358](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L358) | POST   | `orders/return-defer-purchase`                 | `[OrderController::class, 'returnPurchased'])`                                   |
| [routes/api.php:360](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L360) | POST   | `orders/distribute`                            | `[OrderController::class, 'distribute'])`                                        |
| [routes/api.php:361](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L361) | POST   | `orders/repeats`                               | `[OrderController::class, 'repeats'])`                                           |
| [routes/api.php:362](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L362) | POST   | `orders/all-repeats`                           | `[OrderController::class, 'all_repeats'])`                                       |
| [routes/api.php:363](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L363) | POST   | `orders/update_mini`                           | `[OrderController::class, 'update_mini'])`                                       |
| [routes/api.php:364](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L364) | POST   | `orders/visited-mini`                          | `[OrderController::class, 'visited_mini'])`                                      |
| [routes/api.php:365](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L365) | GET    | `orders/histories`                             | `[OrderController::class, 'histories'])`                                         |
| [routes/api.php:368](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L368) | GET    | `templates`                                    | `[SmsTemplateController::class, 'index']);`                                      |
| [routes/api.php:369](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L369) | POST   | `templates`                                    | `[SmsTemplateController::class, 'store']);`                                      |
| [routes/api.php:370](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L370) | DELETE | `templates/{id}`                               | `[SmsTemplateController::class, 'destroy']);`                                    |
| [routes/api.php:373](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L373) | POST   | `orders/blacklist`                             | `[OrderController::class, 'blacklist'])`                                         |
| [routes/api.php:375](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L375) | POST   | `orders/import-excel`                          | `[CrmImportOrdersController::class, 'import'])`                                  |
| [routes/api.php:376](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L376) | POST   | `orders/blacklist-request`                     | `[OrderController::class, 'blacklistRequest'])`                                  |
| [routes/api.php:377](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L377) | GET    | `orders/{id}`                                  | `[OrderController::class, 'orders'])`                                            |
| [routes/api.php:378](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L378) | GET    | `order/delete/{id}`                            | `[OrderController::class, 'delete'])`                                            |
| [routes/api.php:379](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L379) | GET    | `order/{showroom}/detail/{id}`                 | `[OrderController::class, 'detail'])`                                            |
| [routes/api.php:380](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L380) | GET    | `order/trashes`                                | `[OrderController::class, 'trashes'])`                                           |
| [routes/api.php:381](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L381) | GET    | `order/drops`                                  | `[OrderController::class, 'drops'])`                                             |
| [routes/api.php:382](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L382) | GET    | `order/arrival-statuses`                       | `[OrderController::class, 'arrival_statuses'])`                                  |
| [routes/api.php:383](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L383) | GET    | `order-types`                                  | `[OrderController::class, 'types'])`                                             |
| [routes/api.php:384](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L384) | GET    | `order-statuses`                               | `[OrderController::class, 'statuses'])`                                          |
| [routes/api.php:388](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L388) | GET    | `dashboard/arrivals`                           | `[DashboardController::class, 'index'])`                                         |
| [routes/api.php:389](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L389) | POST   | `dashboard/orders`                             | `[DashboardController::class, 'orders'])`                                        |
| [routes/api.php:390](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L390) | GET    | `dashboard/credits`                            | `[DashboardController::class, 'creditRequests'])`                                |
| [routes/api.php:391](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L391) | GET    | `dashboard/used-cars`                          | `[DashboardController::class, 'used_cars'])`                                     |
| [routes/api.php:392](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L392) | GET    | `dashboard/cars`                               | `[DashboardController::class, 'cars'])`                                          |
| [routes/api.php:400](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L400) | POST   | `credit-requests/save`                         | `[CreditRequestController::class, 'saveCreditRequest'])`                         |
| [routes/api.php:402](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L402) | POST   | `credit-requests/delete`                       | `[CreditRequestController::class, 'deleteCreditRequests'])`                      |
| [routes/api.php:403](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L403) | POST   | `credit-requests/sale`                         | `[CreditRequestController::class, 'saleCreditRequest'])`                         |
| [routes/api.php:404](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L404) | POST   | `credit-requests/restore`                      | `[CreditRequestController::class, 'restore'])`                                   |
| [routes/api.php:405](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L405) | POST   | `credit-requests/send-notify`                  | `[CreditNotifyController::class, 'sendNotify'])`                                 |
| [routes/api.php:406](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L406) | POST   | `credit-requests/notify-manager`               | `[CreditNotifyController::class, 'notifyManager'])`                              |
| [routes/api.php:407](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L407) | POST   | `credit-requests/sync`                         | `[CreditRequestController::class, 'syncCreditRequest'])`                         |
| [routes/api.php:411](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L411) | GET    | `banks`                                        | `[ShowroomController::class, 'banks'])`                                          |
| [routes/api.php:412](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L412) | GET    | `regions`                                      | `[ShowroomController::class, 'regions'])`                                        |
| [routes/api.php:413](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L413) | GET    | `sites`                                        | `[ShowroomController::class, 'sites'])`                                          |
| [routes/api.php:414](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L414) | GET    | `site-types`                                   | `[ShowroomController::class, 'site_types'])`                                     |
| [routes/api.php:415](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L415) | POST   | `site/save`                                    | `[ShowroomController::class, 'saveSite'])`                                       |
| [routes/api.php:416](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L416) | POST   | `site/update`                                  | `[ShowroomController::class, 'updateSite'])`                                     |
| [routes/api.php:417](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L417) | POST   | `site/duplicate`                               | `[ShowroomController::class, 'duplicateSite'])`                                  |
| [routes/api.php:418](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L418) | POST   | `site/delete`                                  | `[ShowroomController::class, 'deleteSite'])`                                     |
| [routes/api.php:419](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L419) | GET    | `admin-sites`                                  | `[ShowroomController::class, 'admin_sites'])`                                    |
| [routes/api.php:420](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L420) | GET    | `agencies`                                     | `[ShowroomController::class, 'agencies'])`                                       |
| [routes/api.php:423](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L423) | GET    | `preparers`                                    | `[ShowroomController::class, 'preparers'])`                                      |
| [routes/api.php:427](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L427) | GET    | `permissions`                                  | `[PermissionController::class, 'abilities']);`                                   |
| [routes/api.php:428](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L428) | GET    | `abilities/list`                               | `[PermissionController::class, 'allAbilities']);`                                |
| [routes/api.php:429](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L429) | POST   | `permission/create`                            | `[PermissionController::class, 'create']);`                                      |
| [routes/api.php:430](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L430) | GET    | `permission/allow`                             | `[PermissionController::class, 'allow']);`                                       |
| [routes/api.php:431](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L431) | GET    | `permission/disallow`                          | `[PermissionController::class, 'disallow']);`                                    |
| [routes/api.php:432](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L432) | GET    | `permission/retract`                           | `[PermissionController::class, 'retract']);`                                     |
| [routes/api.php:433](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L433) | GET    | `permission/forbid`                            | `[PermissionController::class, 'forbid']);`                                      |
| [routes/api.php:434](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L434) | GET    | `user-permissions/{id}`                        | `[PermissionController::class, 'user_permissions']);`                            |
| [routes/api.php:435](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L435) | GET    | `user-permissions-rows/{id}`                   | `[PermissionController::class, 'userPermissionRows']);`                          |
| [routes/api.php:436](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L436) | POST   | `user-permissions/save-bulk`                   | `[PermissionController::class, 'saveUserPermissions']);`                         |
| [routes/api.php:437](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L437) | GET    | `roles`                                        | `RoleController::class);`                                                        |
| [routes/api.php:438](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L438) | GET    | `operator/roles`                               | `[RoleController::class, 'operator_roles']);`                                    |
| [routes/api.php:440](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L440) | GET    | `get-tabs`                                     | `[PageController::class, 'getTabs']);`                                           |
| [routes/api.php:441](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L441) | GET    | `pages`                                        | `[PageController::class, 'pages']);`                                             |
| [routes/api.php:443](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L443) | GET    | `test`                                         | `function () {`                                                                  |
| [routes/api.php:456](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L456) | GET    | `schedules`                                    | `[OperatorController::class, 'schedules'])`                                      |
| [routes/api.php:457](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L457) | POST   | `schedules/create`                             | `[OperatorController::class, 'createSchedule'])`                                 |
| [routes/api.php:458](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L458) | POST   | `schedules/update`                             | `[OperatorController::class, 'updateSchedule'])`                                 |
| [routes/api.php:459](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L459) | POST   | `schedules/delete`                             | `[OperatorController::class, 'deleteSchedule'])`                                 |
| [routes/api.php:460](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L460) | POST   | `crm/workplace`                                | `[CrmController::class, 'setWorkPlace'])`                                        |
| [routes/api.php:463](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L463) | GET    | `programmatic-schedules`                       | `[OperatorController::class, 'programmaticSchedules'])`                          |
| [routes/api.php:464](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L464) | POST   | `programmatic-schedules/create`                | `[OperatorController::class, 'createProgrammaticSchedule'])`                     |
| [routes/api.php:465](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L465) | POST   | `programmatic-schedules/update`                | `[OperatorController::class, 'updateProgrammaticSchedule'])`                     |
| [routes/api.php:466](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L466) | POST   | `programmatic-schedules/delete`                | `[OperatorController::class, 'deleteProgrammaticSchedule'])`                     |
| [routes/api.php:469](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L469) | GET    | `test/shedule`                                 | `[OrderController::class, 'shedule'])`                                           |
| [routes/api.php:472](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L472) | GET    | `fares`                                        | `[FareController::class, 'fares'])`                                              |
| [routes/api.php:473](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L473) | POST   | `fares/save`                                   | `[FareController::class, 'save'])`                                               |
| [routes/api.php:475](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L475) | POST   | `fares/delete`                                 | `[FareController::class, 'delete'])`                                             |
| [routes/api.php:476](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L476) | POST   | `fares/upload`                                 | `[FareController::class, 'upload'])`                                             |
| [routes/api.php:484](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L484) | POST   | `report/status`                                | `[ReportController::class, 'byStatus']);`                                        |
| [routes/api.php:485](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L485) | POST   | `report/sites`                                 | `[ReportController::class, 'bySite']);`                                          |
| [routes/api.php:486](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L486) | POST   | `report-victory/sites`                         | `[VictoryReportController::class, 'bySite']);`                                   |
| [routes/api.php:487](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L487) | POST   | `report/source`                                | `[ReportController::class, 'bySource']);`                                        |
| [routes/api.php:488](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L488) | POST   | `report/content`                               | `[ReportController::class, 'byContent']);`                                       |
| [routes/api.php:489](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L489) | POST   | `report/campaign`                              | `[ReportController::class, 'byCampaign']);`                                      |
| [routes/api.php:490](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L490) | POST   | `report/medium`                                | `[ReportController::class, 'byMedium']);`                                        |
| [routes/api.php:491](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L491) | POST   | `report/term`                                  | `[ReportController::class, 'byTerm']);`                                          |
| [routes/api.php:492](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L492) | POST   | `report/date`                                  | `[ReportController::class, 'byDates']);`                                         |
| [routes/api.php:495](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L495) | POST   | `report/sites-agency`                          | `[ReportController::class, 'bySiteAgency']);`                                    |
| [routes/api.php:497](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L497) | POST   | `report/agency/payment`                        | `[ReportController::class, 'payment']);`                                         |
| [routes/api.php:499](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L499) | POST   | `report/agency/target-website-webhook`         | `[ReportController::class, 'bySitesWebhook']);`                                  |
| [routes/api.php:500](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L500) | POST   | `report/agency/dates`                          | `[ReportController::class, 'byDates']);`                                         |
| [routes/api.php:501](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L501) | POST   | `report/agency`                                | `[ReportController::class, 'byAgency']);`                                        |
| [routes/api.php:502](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L502) | POST   | `report/drops`                                 | `[ReportController::class, 'drops']);`                                           |
| [routes/api.php:506](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L506) | POST   | `/prostor/sms/send`                            | `[ProstorSmsController::class, 'sendSms'])`                                      |
| [routes/api.php:517](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L517) | POST   | `crm/import`                                   | `[ImportRequestController::class, 'import'])`                                    |
| [routes/api.php:519](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L519) | POST   | `/events/{id}`                                 | `[MangoController::class, 'checkPhone2']);`                                      |
| [routes/api.php:520](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L520) | POST   | `clear-notify`                                 | `[MangoController::class, 'clearNotify']);`                                      |
| [routes/api.php:521](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L521) | POST   | `call`                                         | `[MangoCommandController::class, 'makeCall'])`                                   |
| [routes/api.php:522](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L522) | POST   | `checkPhone2`                                  | `[MangoController::class, 'checkPhone2'])`                                       |
| [routes/api.php:523](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L523) | GET    | `testrest`                                     | `[OrderController::class, 'test']);`                                             |
| [routes/api.php:524](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L524) | POST   | `request-report`                               | `[DataAvController::class, 'report']);`                                          |
| [routes/api.php:525](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/api.php#L525) | POST   | `number`                                       | `[DataAvController::class, 'number']);`                                          |
| [routes/web.php:25](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/web.php#L25)   | GET    | `dilo`                                         | `[\Rap2hpoutre\LaravelLogViewer\LogViewerController::class, 'index']);`          |
| [routes/web.php:45](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/routes/web.php#L45)   | ANY    | `{all}`                                        | `function () {`                                                                  |

### Д. Клиентские страницы и обращения к API

Декларации страниц не гарантируют подключение в router. API-паттерны извлечены статически; динамические строки могут быть неполны.

- [client/pages/admin/auth-logs.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/auth-logs.vue)
- [client/pages/admin/blacklist.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/blacklist.vue)
- [client/pages/admin/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/index.vue) — `/get-tabs`
- [client/pages/admin/managers.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/managers.vue)
- [client/pages/admin/marks.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/marks.vue)
- [client/pages/admin/models.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/models.vue)
- [client/pages/admin/operators.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/operators.vue) — `/users/reset-passwords`
- [client/pages/admin/sites.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/admin/sites.vue)
- [client/pages/ads/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/ads/_id.vue)
- [client/pages/ads/dashboard.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/ads/dashboard.vue)
- [client/pages/ads/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/ads/index.vue)
- [client/pages/auth/profile.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/auth/profile.vue)
- [client/pages/auth/signin.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/auth/signin.vue)
- [client/pages/car/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car/_id.vue)
- [client/pages/car/all.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car/all.vue)
- [client/pages/car/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car/index.vue)
- [client/pages/car/sold.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car/sold.vue)
- [client/pages/car/transits.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car/transits.vue)
- [client/pages/car2/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car2/_id.vue)
- [client/pages/car2/all.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car2/all.vue)
- [client/pages/car2/car-logs.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car2/car-logs.vue)
- [client/pages/car2/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car2/index.vue)
- [client/pages/car2/transits.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/car2/transits.vue)
- [client/pages/crm/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/_id.vue)
- [client/pages/crm/analytics.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/analytics.vue)
- [client/pages/crm/blacklist-orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/blacklist-orders.vue) — `orders/repeats`
- [client/pages/crm/blacklist.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/blacklist.vue)
- [client/pages/crm/classified_orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/classified_orders.vue) — `orders/repeats`, `/export/no-answer`, `/export/arrive`, `https://a-c77.ru/api/prostor/sms/send`, `/sms/templates/${item.id}`, `/sms/templates`
- [client/pages/crm/edit_mini_programmatic_autopole.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/edit_mini_programmatic_autopole.vue)
- [client/pages/crm/edit_mini_programmatic_v.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/edit_mini_programmatic_v.vue)
- [client/pages/crm/import-orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/import-orders.vue) — `/orders/import-excel`
- [client/pages/crm/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/index.vue)
- [client/pages/crm/light_orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/light_orders.vue) — `orders/repeats`
- [client/pages/crm/order_detail.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/order_detail.vue)
- [client/pages/crm/order_edit.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/order_edit.vue) — `/roles`, `/permissions`, `orders/delete-deferred-item`, `https://a-c77.ru/api/prostor/sms/send`, `/sms/templates/${item.id}`, `/sms/templates`
- [client/pages/crm/order_edit_mini.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/order_edit_mini.vue)
- [client/pages/crm/order_edit_mini_classified.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/order_edit_mini_classified.vue) — `/roles`, `/permissions`, `orders/delete-deferred-item`, `https://a-c77.ru/api/prostor/sms/send`, `/sms/templates/${item.id}`, `/sms/templates`
- [client/pages/crm/orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/orders.vue) — `orders/repeats`, `/export/no-answer`, `/export/arrive`, `https://a-c77.ru/api/prostor/sms/send`, `/sms/templates/${item.id}`, `/sms/templates`
- [client/pages/crm/orders/filter.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/orders/filter.vue) — `orders/repeats`
- [client/pages/crm/orders_deferred_purchases.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/orders_deferred_purchases.vue) — `orders/repeats`, `/export/no-answer`
- [client/pages/crm/orders_edit_mini_programmatic.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/orders_edit_mini_programmatic.vue)
- [client/pages/crm/orders_mini.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/orders_mini.vue)
- [client/pages/crm/programmatic_autopole_orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/programmatic_autopole_orders.vue) — `orders/repeats`
- [client/pages/crm/programmatic_orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/programmatic_orders.vue) — `orders/repeats`
- [client/pages/crm/programmatic_v_orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/programmatic_v_orders.vue) — `orders/repeats`
- [client/pages/crm/report/_agency.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/report/_agency.vue)
- [client/pages/crm/report/_victory.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/report/_victory.vue)
- [client/pages/crm/report/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/report/index.vue)
- [client/pages/crm/report/orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/report/orders.vue)
- [client/pages/crm/reports.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/reports.vue)
- [client/pages/crm/reports_victory.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/reports_victory.vue)
- [client/pages/crm/schedules.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/schedules.vue)
- [client/pages/crm/victory.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/crm/victory.vue)
- [client/pages/dashboard/analytics.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/dashboard/analytics.vue)
- [client/pages/dashboard/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/dashboard/index.vue)
- [client/pages/dashboard/index2.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/dashboard/index2.vue)
- [client/pages/home.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/home.vue) — `/get-tabs`
- [client/pages/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/index.vue) — `/get-tabs`
- [client/pages/report/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/_id.vue)
- [client/pages/report/classified.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/classified.vue)
- [client/pages/report/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/index.vue)
- [client/pages/report/inhouse.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/inhouse.vue)
- [client/pages/report/justwe.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/justwe.vue)
- [client/pages/report/projects.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/projects.vue)
- [client/pages/report/report/_agency.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/report/_agency.vue)
- [client/pages/report/report/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/report/index.vue)
- [client/pages/report/report/orders.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/report/orders.vue)
- [client/pages/report/reports.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/report/reports.vue)
- [client/pages/setting/sites.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/setting/sites.vue)
- [client/pages/tablet/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/tablet/_id.vue)
- [client/pages/tablet/arrivals/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/tablet/arrivals/_id.vue)
- [client/pages/tablet/arrivals/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/tablet/arrivals/index.vue)
- [client/pages/tablet/arrivals2.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/tablet/arrivals2.vue)
- [client/pages/tablet/credit-request2.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/tablet/credit-request2.vue)
- [client/pages/tablet/credit-requests/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/tablet/credit-requests/_id.vue)
- [client/pages/tablet/credit-requests/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/tablet/credit-requests/index.vue)
- [client/pages/tablet/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/tablet/index.vue)
- [client/pages/usedcar/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/_id.vue)
- [client/pages/usedcar/all.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/all.vue)
- [client/pages/usedcar/cars.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/cars.vue)
- [client/pages/usedcar/edit.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/edit.vue)
- [client/pages/usedcar/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/index.vue)
- [client/pages/usedcar/light.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/light.vue)
- [client/pages/usedcar/list.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/list.vue)
- [client/pages/usedcar/purchase.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/purchase.vue)
- [client/pages/usedcar/simple.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/simple.vue)
- [client/pages/usedcar/sold.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/sold.vue)
- [client/pages/usedcar/transits.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/transits.vue)
- [client/pages/usedcar/upload.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcar/upload.vue)
- [client/pages/usedcars/_id.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcars/_id.vue)
- [client/pages/usedcars/all.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcars/all.vue)
- [client/pages/usedcars/cars.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcars/cars.vue)
- [client/pages/usedcars/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcars/index.vue)
- [client/pages/usedcars/transits.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcars/transits.vue)
- [client/pages/usedcars/used-logs.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/usedcars/used-logs.vue)
- [client/pages/user/index.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/user/index.vue)
- [client/pages/user/user-edit.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/user/user-edit.vue)
- [client/pages/user/users.vue](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/client/pages/user/users.vue) — `/abilities/list`, `/user-permissions-rows/${this.selectedUserId}`, `/user-permissions/save-bulk`, `/impersonate/${item.id}`, `sync-user`

### Е. Миграции и тесты в репозитории

Ниже перечислены существующие файлы. В этом аудите они не запускались.

- [database/migrations/0000_00_00_000000_create_websockets_statistics_entries_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/0000_00_00_000000_create_websockets_statistics_entries_table.php)
- [database/migrations/2014_10_12_000000_create_users_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2014_10_12_000000_create_users_table.php)
- [database/migrations/2014_10_12_100000_create_password_resets_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2014_10_12_100000_create_password_resets_table.php)
- [database/migrations/2019_08_19_000000_create_failed_jobs_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2019_08_19_000000_create_failed_jobs_table.php)
- [database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php)
- [database/migrations/2020_11_20_100001_create_log_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2020_11_20_100001_create_log_table.php)
- [database/migrations/2022_07_28_132616_create_activity_log_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2022_07_28_132616_create_activity_log_table.php)
- [database/migrations/2022_09_22_163130_create_permission_tables.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2022_09_22_163130_create_permission_tables.php)
- [database/migrations/2022_09_23_174632_create_bouncer_tables.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2022_09_23_174632_create_bouncer_tables.php)
- [database/migrations/2023_08_30_181808_create_authentication_log_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2023_08_30_181808_create_authentication_log_table.php)
- [database/migrations/2023_10_04_143228_create_last_request_time_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2023_10_04_143228_create_last_request_time_table.php)
- [database/migrations/2024_12_06_151306_create_passed_orders_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2024_12_06_151306_create_passed_orders_table.php)
- [database/migrations/2025_01_21_180005_create_blacklist_orders_table.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2025_01_21_180005_create_blacklist_orders_table.php)
- [database/migrations/2026_08_31_090000_create_mango_calls_tables.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/database/migrations/2026_08_31_090000_create_mango_calls_tables.php)
- [tests/CreatesApplication.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/tests/CreatesApplication.php)
- [tests/Feature/ApiBenchmarkTest.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/tests/Feature/ApiBenchmarkTest.php)
- [tests/Feature/ExampleTest.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/tests/Feature/ExampleTest.php)
- [tests/TestCase.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/tests/TestCase.php)
- [tests/Unit/ExampleTest.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/tests/Unit/ExampleTest.php)
- [tests/Unit/Mango/MangoCallDataTest.php](https://github.com/dilovar-91/acp_crm/blob/87a1672dea62b54802b6fd212a7ca43130b9efe2/tests/Unit/Mango/MangoCallDataTest.php)

### Ж. Полный список отслеживаемых файлов

Этот перечень фиксирует версию аудита, включая шаблоны и assets; не означает ручной проверки каждого ресурса.

```text
.editorconfig
.env.example
.gitattributes
.gitignore
.styleci.yml
README.md
app/Console/Commands/ImportAgency.php
app/Console/Commands/ImportAllPV.php
app/Console/Commands/ImportAura.php
app/Console/Commands/ImportAutodom.php
app/Console/Commands/ImportAutopole.php
app/Console/Commands/ImportAvangard.php
app/Console/Commands/ImportInHousePV.php
app/Console/Commands/ImportJustwe.php
app/Console/Commands/ImportJustweAPlus.php
app/Console/Commands/ImportJustwePV.php
app/Console/Commands/ImportJustweVDL.php
app/Console/Commands/ImportRequest.php
app/Console/Commands/ImportVA.php
app/Console/Commands/ImportVDL.php
app/Console/Commands/ImportVDLKrasnodar.php
app/Console/Commands/ImportVDLKrasnodar2.php
app/Console/Commands/ImportVLL.php
app/Console/Commands/ImportVLLKrasnodar.php
app/Console/Commands/ImportVLLKrasnodar2.php
app/Console/Commands/MangoDealOrders.php
app/Console/Commands/OrderPassAo.php
app/Console/Commands/ReturnDeferredPurchases.php
app/Console/Commands/SendCalltouchOrders.php
app/Console/Kernel.php
app/Events/ArrivalCreated.php
app/Events/CarAdded.php
app/Events/ClearNotify.php
app/Events/ClientProcessed.php
app/Events/ConsultationProceed.php
app/Events/CreditCallProcessed.php
app/Events/CreditCreated.php
app/Events/MangoIncome.php
app/Events/NotificationSend.php
app/Events/OrderCreated.php
app/Events/OrderExcelCreated.php
app/Events/OrderProcessed.php
app/Events/OrderPvCreated.php
app/Events/SaleCreditProcessed.php
app/Events/UsedCarAdded.php
app/Exceptions/Handler.php
app/Exports/ArriveExport.php
app/Exports/InhouseExport.php
app/Exports/NoAnswerExport.php
app/Foundation/Bootstrap/HandleExceptions.php
app/Helpers/GeneralHelper.php
app/Helpers/ShowroomHelper.php
app/Http/Controllers/ActivityController.php
app/Http/Controllers/Admin/CarController.php
app/Http/Controllers/ArrivalController.php
app/Http/Controllers/Auth/PermissionController.php
app/Http/Controllers/Auth/RoleController.php
app/Http/Controllers/AuthController.php
app/Http/Controllers/AuthLogController.php
app/Http/Controllers/AutospotController.php
app/Http/Controllers/BlacklistController.php
app/Http/Controllers/CarController.php
app/Http/Controllers/CarPropertyController.php
app/Http/Controllers/Controller.php
app/Http/Controllers/CreditNotifyController.php
app/Http/Controllers/CreditRequestController.php
app/Http/Controllers/Crm/CrmImportOrdersController.php
app/Http/Controllers/CrmController.php
app/Http/Controllers/DashboardController.php
app/Http/Controllers/DataAvController.php
app/Http/Controllers/ExportController.php
app/Http/Controllers/FareController.php
app/Http/Controllers/GravitelController.php
app/Http/Controllers/Import/ImportAllPVController.php
app/Http/Controllers/Import/ImportCsvController.php
app/Http/Controllers/Import/ImportJustweController.php
app/Http/Controllers/Import/ImportJustwePvController.php
app/Http/Controllers/Import/ImportVLLController.php
app/Http/Controllers/Import/ImportVLLKrasnodar2Controller.php
app/Http/Controllers/Import/ImportVLLKrasnodarController.php
app/Http/Controllers/Import/ImportVaController.php
app/Http/Controllers/ImportAgencyController.php
app/Http/Controllers/ImportAuraController.php
app/Http/Controllers/ImportAutodomController.php
app/Http/Controllers/ImportAutopoleController.php
app/Http/Controllers/ImportAvangardController.php
app/Http/Controllers/ImportInHousePVController.php
app/Http/Controllers/ImportJustweController.php
app/Http/Controllers/ImportJustweVdlController.php
app/Http/Controllers/ImportRequestController.php
app/Http/Controllers/ImportVdlController.php
app/Http/Controllers/ImportVdlKrasnodar2Controller.php
app/Http/Controllers/ImportVdlKrasnodarController.php
app/Http/Controllers/JivoWebhookController.php
app/Http/Controllers/LeadController.php
app/Http/Controllers/MangoCallTrackingController.php
app/Http/Controllers/MangoCommandController.php
app/Http/Controllers/MangoController.php
app/Http/Controllers/MangoWebhookController.php
app/Http/Controllers/OperatorController.php
app/Http/Controllers/OrderController.php
app/Http/Controllers/OrderCountController.php
app/Http/Controllers/PageController.php
app/Http/Controllers/ProjectController.php
app/Http/Controllers/ProstorSmsController.php
app/Http/Controllers/QuizController.php
app/Http/Controllers/RecordController.php
app/Http/Controllers/ReportController.php
app/Http/Controllers/ShipmentController.php
app/Http/Controllers/ShowroomController.php
app/Http/Controllers/SmsTemplateController.php
app/Http/Controllers/SummaryReportController.php
app/Http/Controllers/TargetController.php
app/Http/Controllers/TestController.php
app/Http/Controllers/TildaController.php
app/Http/Controllers/TransitController.php
app/Http/Controllers/UdpautoController.php
app/Http/Controllers/UsedCarController.php
app/Http/Controllers/UserController.php
app/Http/Controllers/Victory/PassAoController.php
app/Http/Controllers/VictoryReportController.php
app/Http/Kernel.php
app/Http/Middleware/ApiToken.php
app/Http/Middleware/ApiToken2.php
app/Http/Middleware/ApiTokenAutospot.php
app/Http/Middleware/ApiTokenTilda.php
app/Http/Middleware/Authenticate.php
app/Http/Middleware/EncryptCookies.php
app/Http/Middleware/PreventRequestsDuringMaintenance.php
app/Http/Middleware/RedirectIfAuthenticated.php
app/Http/Middleware/TrimStrings.php
app/Http/Middleware/TrustHosts.php
app/Http/Middleware/TrustProxies.php
app/Http/Middleware/TrustedAccess.php
app/Http/Middleware/VerifyCsrfToken.php
app/Jobs/CallTouchDealUpdate.php
app/Jobs/DealUpdate.php
app/Jobs/ProcessCall.php
app/Jobs/ProcessDistribute.php
app/Jobs/ProcessRecord.php
app/Listeners/MangoIncomeEventListener.php
app/Listeners/OrderAddedEventListener.php
app/Listeners/OrderAddedExcelEventListener.php
app/Listeners/OrderAddedPvEventListener.php
app/Listeners/OrderEventListener.php
app/Models/ActivityLog.php
app/Models/ActualProject.php
app/Models/Agency.php
app/Models/AgencyType.php
app/Models/Arrival.php
app/Models/AuthLog.php
app/Models/Bank.php
app/Models/Blacklist.php
app/Models/BlacklistOrder.php
app/Models/BodyType.php
app/Models/Brand.php
app/Models/CallCount.php
app/Models/CallHistory.php
app/Models/CallRecord.php
app/Models/CalltouchOrder.php
app/Models/CalltouchUpdateDeal.php
app/Models/Car.php
app/Models/CarModel.php
app/Models/Color.php
app/Models/Company.php
app/Models/Competitors.php
app/Models/Complectation.php
app/Models/CreditRequest.php
app/Models/CustomActivity.php
app/Models/DeferredPurchase.php
app/Models/Dilo.php
app/Models/Driver.php
app/Models/EngineType.php
app/Models/Fare.php
app/Models/JustweBrand.php
app/Models/JustweModel.php
app/Models/LastQueue.php
app/Models/LastRequest.php
app/Models/Log.php
app/Models/Manager.php
app/Models/MangoCall.php
app/Models/MangoCallRecording.php
app/Models/MissedCall.php
app/Models/Operator.php
app/Models/OperatorSchedule.php
app/Models/Order.php
app/Models/OrderArrival.php
app/Models/OrderDrop.php
app/Models/OrderHistory.php
app/Models/OrderSource.php
app/Models/OrderStatus.php
app/Models/OrderTrash.php
app/Models/OrderType.php
app/Models/Page.php
app/Models/PassedOrder.php
app/Models/Permission.php
app/Models/Phone.php
app/Models/PhoneCode.php
app/Models/PhoneOperator.php
app/Models/PhoneRegion.php
app/Models/Preparer.php
app/Models/ProgrammaticSchedule.php
app/Models/ProjectManager.php
app/Models/ProjectStatus.php
app/Models/ProjectSystem.php
app/Models/ProjectType.php
app/Models/Region.php
app/Models/Role.php
app/Models/Shipment.php
app/Models/ShipmentCar.php
app/Models/ShipmentDetail.php
app/Models/ShipmentStatus.php
app/Models/ShipmentUsedCar.php
app/Models/Showroom.php
app/Models/Site.php
app/Models/SiteType.php
app/Models/SmsMessage.php
app/Models/SmsTemplate.php
app/Models/Status.php
app/Models/Test.php
app/Models/Transit.php
app/Models/Transmission.php
app/Models/UsedCar.php
app/Models/UsedcarTransit.php
app/Models/User.php
app/Models/WheelType.php
app/Models/WorkPlace.php
app/Notifications/CreditApprove.php
app/Notifications/NotifyApproveManager.php
app/Notifications/NotifyCancelOperator.php
app/Notifications/NotifyConsultationOperator.php
app/Notifications/ResetPassword.php
app/Notifications/VerifyEmail.php
app/Providers/AppServiceProvider.php
app/Providers/AuthServiceProvider.php
app/Providers/BroadcastServiceProvider.php
app/Providers/EventServiceProvider.php
app/Providers/RouteServiceProvider.php
app/Services/BlacklistService.php
app/Services/CalltouchDealUpdateService.php
app/Services/DistributeService.php
app/Services/Mango/MangoCallData.php
app/Services/Mango/MangoCallService.php
app/Services/Mango/MangoSiteResolver.php
app/Services/MangoDealUpdateService.php
app/Services/OrderDistributeService.php
app/Services/ProstorSmsService.php
artisan
bootstrap/app.php
bootstrap/cache/.gitignore
client/.editorconfig
client/.eslintrc.js
client/.prettierignore
client/.prettierrc
client/README.md
client/apps/board/BoardApp.vue
client/apps/board/components/BoardCard.vue
client/apps/board/pages/BoardPage.vue
client/apps/board/routes/index.js
client/apps/board/store/content/cards.js
client/apps/board/store/index.js
client/apps/board/store/mutations.js
client/apps/board/store/state.js
client/apps/chat/ChatApp.vue
client/apps/chat/components/ChannelMessage.vue
client/apps/chat/components/EmojiPicker.vue
client/apps/chat/components/InputBox.vue
client/apps/chat/components/UserAvatar.vue
client/apps/chat/content/messages.js
client/apps/chat/pages/ChannelPage.vue
client/apps/chat/routes/index.js
client/apps/email/EmailApp.vue
client/apps/email/components/EmailCompose.vue
client/apps/email/components/EmailEditor.vue
client/apps/email/components/EmailInput.vue
client/apps/email/components/EmailList.vue
client/apps/email/components/EmailMenu.vue
client/apps/email/pages/DraftsPage.vue
client/apps/email/pages/InboxPage.vue
client/apps/email/pages/SentPage.vue
client/apps/email/pages/StarredPage.vue
client/apps/email/pages/TrashPage.vue
client/apps/email/pages/ViewPage.vue
client/apps/email/routes/index.js
client/apps/email/store/actions.js
client/apps/email/store/content/inbox.js
client/apps/email/store/content/starred.js
client/apps/email/store/index.js
client/apps/email/store/mutations.js
client/apps/email/store/state.js
client/apps/todo/TodoApp.vue
client/apps/todo/components/TodoCompose.vue
client/apps/todo/components/TodoList.vue
client/apps/todo/components/TodoMenu.vue
client/apps/todo/pages/CompletedPage.vue
client/apps/todo/pages/LabelPage.vue
client/apps/todo/pages/TasksPage.vue
client/apps/todo/routes/index.js
client/apps/todo/store/content/tasks.js
client/apps/todo/store/getters.js
client/apps/todo/store/index.js
client/apps/todo/store/mutations.js
client/apps/todo/store/state.js
client/assets/images/logo-alarm.svg
client/assets/variables.css
client/assets/variables.css.map
client/assets/variables.scss
client/components/BreadCrumb.vue
client/components/CustomDataPicker.vue
client/components/DPicker.vue
client/components/DTPicker.vue
client/components/DashboardCard2.vue
client/components/DateTimePicker.vue
client/components/PhoneMask.vue
client/components/RepeatDialog.vue
client/components/Sidebar.vue
client/components/StatusComponent.vue
client/components/ToastComponent.vue
client/components/common/ApexCharts.js
client/components/common/CopyLabel.vue
client/components/common/FlagIcon.vue
client/components/common/TrendPercent.vue
client/components/country_telephone_data.js
client/components/dashboard/ActivityCard.vue
client/components/dashboard/InboxCard.vue
client/components/dashboard/SalesCard.vue
client/components/dashboard/ShowroomCard.vue
client/components/dashboard/SourcesCard.vue
client/components/dashboard/TableCard.vue
client/components/dashboard/TicketsCard.vue
client/components/dashboard/TodoCard.vue
client/components/dashboard/TrackCard.vue
client/components/landing/CallToAction.vue
client/components/landing/Faq.vue
client/components/landing/Feature1.vue
client/components/landing/Feature2.vue
client/components/landing/Partners.vue
client/components/landing/Pricing.vue
client/components/landing/Pricing2.vue
client/components/landing/Stats.vue
client/components/navigation/MainMenu.vue
client/components/navigation/NavMenu.vue
client/components/navigation/NavMenuItem.vue
client/components/report/ByAgencyPayment.vue
client/components/report/ByAgencySites.vue
client/components/report/ByAgencyVictorySites.vue
client/components/report/ByOperator.vue
client/components/report/ByOperatorAgency.vue
client/components/report/ByOperatorApprovals.vue
client/components/report/ByOperatorPv.vue
client/components/report/ByOperatorVictory.vue
client/components/report/OperatorReport.vue
client/components/report/ReportByAgency.vue
client/components/report/ReportByCampaign.vue
client/components/report/ReportByCampaignAgency.vue
client/components/report/ReportByContent.vue
client/components/report/ReportByDate.vue
client/components/report/ReportByMedium.vue
client/components/report/ReportBySites.vue
client/components/report/ReportBySource.vue
client/components/report/ReportByStatus.vue
client/components/report/ReportByTerm.vue
client/components/report/ReportByUtm.vue
client/components/report/ReportUtmExtend.vue
client/components/report/SummaryApprovals.vue
client/components/report/SummaryArrivals.vue
client/components/report/SummaryBySites.vue
client/components/report/SummaryDrops.vue
client/components/report/SummaryOperator.vue
client/components/report/SummaryReport.vue
client/components/report/SummaryReportAgency.vue
client/components/report/SummaryVictory.vue
client/components/report/VictoryOperator.vue
client/components/toolbar/ToolbarApps.vue
client/components/toolbar/ToolbarCurrency.vue
client/components/toolbar/ToolbarLanguage.vue
client/components/toolbar/ToolbarNotifications.vue
client/components/toolbar/ToolbarUser.vue
client/components/user/AccountTab.vue
client/components/user/InformationTab.vue
client/configs/activity.json
client/configs/analytics.js
client/configs/car.json
client/configs/currencies.js
client/configs/icons.js
client/configs/index.js
client/configs/locales.js
client/configs/maps.js
client/configs/menus/pages.menu.js
client/configs/navigation.js
client/configs/theme.js
client/configs/time.js
client/configs/toolbar.js
client/configs/vuetify.js
client/filters/capitalize.js
client/filters/formatCurrency.js
client/filters/formatDate.js
client/filters/lowercase.js
client/filters/uppercase.js
client/jsconfig.json
client/layouts/agency.vue
client/layouts/auth.vue
client/layouts/crm.vue
client/layouts/crm_pv.vue
client/layouts/default.vue
client/layouts/edit.vue
client/layouts/error.vue
client/layouts/simple.vue
client/layouts/victory.vue
client/middleware/admin.js
client/middleware/permission.js
client/nuxt.config.js
client/pages/admin/auth-logs.vue
client/pages/admin/blacklist.vue
client/pages/admin/index.vue
client/pages/admin/managers.vue
client/pages/admin/marks.vue
client/pages/admin/models.vue
client/pages/admin/operators.vue
client/pages/admin/sites.vue
client/pages/ads/_id.vue
client/pages/ads/dashboard.vue
client/pages/ads/index.vue
client/pages/auth/profile.vue
client/pages/auth/signin.vue
client/pages/car/_id.vue
client/pages/car/all.vue
client/pages/car/index.vue
client/pages/car/sold.vue
client/pages/car/transits.vue
client/pages/car2/_id.vue
client/pages/car2/all.vue
client/pages/car2/car-logs.vue
client/pages/car2/index.vue
client/pages/car2/transits.vue
client/pages/crm/_id.vue
client/pages/crm/analytics.vue
client/pages/crm/blacklist-orders.vue
client/pages/crm/blacklist.vue
client/pages/crm/classified_orders.vue
client/pages/crm/edit_mini_programmatic_autopole.vue
client/pages/crm/edit_mini_programmatic_v.vue
client/pages/crm/import-orders.vue
client/pages/crm/index.vue
client/pages/crm/light_orders.vue
client/pages/crm/order_detail.vue
client/pages/crm/order_edit.vue
client/pages/crm/order_edit_mini.vue
client/pages/crm/order_edit_mini_classified.vue
client/pages/crm/orders.vue
client/pages/crm/orders/filter.vue
client/pages/crm/orders_deferred_purchases.vue
client/pages/crm/orders_edit_mini_programmatic.vue
client/pages/crm/orders_mini.vue
client/pages/crm/programmatic_autopole_orders.vue
client/pages/crm/programmatic_orders.vue
client/pages/crm/programmatic_v_orders.vue
client/pages/crm/report/_agency.vue
client/pages/crm/report/_victory.vue
client/pages/crm/report/index.vue
client/pages/crm/report/orders.vue
client/pages/crm/reports.vue
client/pages/crm/reports_victory.vue
client/pages/crm/schedules.vue
client/pages/crm/victory.vue
client/pages/dashboard/analytics.vue
client/pages/dashboard/index.vue
client/pages/dashboard/index2.vue
client/pages/home.vue
client/pages/index.vue
client/pages/report/_id.vue
client/pages/report/classified.vue
client/pages/report/index.vue
client/pages/report/inhouse.vue
client/pages/report/justwe.vue
client/pages/report/projects.vue
client/pages/report/report/_agency.vue
client/pages/report/report/index.vue
client/pages/report/report/orders.vue
client/pages/report/reports.vue
client/pages/setting/sites.vue
client/pages/tablet/_id.vue
client/pages/tablet/arrivals/_id.vue
client/pages/tablet/arrivals/index.vue
client/pages/tablet/arrivals2.vue
client/pages/tablet/credit-request2.vue
client/pages/tablet/credit-requests/_id.vue
client/pages/tablet/credit-requests/index.vue
client/pages/tablet/index.vue
client/pages/usedcar/_id.vue
client/pages/usedcar/activity.json
client/pages/usedcar/all.vue
client/pages/usedcar/cars.vue
client/pages/usedcar/edit.vue
client/pages/usedcar/index.vue
client/pages/usedcar/light.vue
client/pages/usedcar/list.vue
client/pages/usedcar/purchase.vue
client/pages/usedcar/simple.vue
client/pages/usedcar/sold.vue
client/pages/usedcar/transits.vue
client/pages/usedcar/upload.vue
client/pages/usedcars/_id.vue
client/pages/usedcars/all.vue
client/pages/usedcars/cars.vue
client/pages/usedcars/index.vue
client/pages/usedcars/transits.vue
client/pages/usedcars/used-logs.vue
client/pages/user/index.vue
client/pages/user/user-edit.vue
client/pages/user/users.vue
client/plugins/animate.js
client/plugins/apexcharts.js
client/plugins/filters.js
client/plugins/global.js
client/plugins/moment.js
client/plugins/v-mask.js
client/plugins/vidle.js
client/plugins/vue-gates.js
client/plugins/vue-loading-overlay.js
client/plugins/vue-print-nb.js
client/plugins/vue-shortkey.js
client/plugins/vue2-datepicker.js
client/router.js
client/static/favicon.ico
client/static/icon.png
client/static/images/atlant-logo.png
client/static/images/autru.png
client/static/images/avatars/avatar1.svg
client/static/images/avatars/avatar10.svg
client/static/images/avatars/avatar11.svg
client/static/images/avatars/avatar12.svg
client/static/images/avatars/avatar13.svg
client/static/images/avatars/avatar14.svg
client/static/images/avatars/avatar15.svg
client/static/images/avatars/avatar16.svg
client/static/images/avatars/avatar17.svg
client/static/images/avatars/avatar18.svg
client/static/images/avatars/avatar19.svg
client/static/images/avatars/avatar2.svg
client/static/images/avatars/avatar20.svg
client/static/images/avatars/avatar3.svg
client/static/images/avatars/avatar4.svg
client/static/images/avatars/avatar5.svg
client/static/images/avatars/avatar6.svg
client/static/images/avatars/avatar7.svg
client/static/images/avatars/avatar8.svg
client/static/images/avatars/avatar9.svg
client/static/images/avrora-logo.svg
client/static/images/background-user.jpg
client/static/images/illustrations/404-illustration.svg
client/static/images/illustrations/500-illustration.svg
client/static/images/illustrations/maintenance-illustration.svg
client/static/images/illustrations/signin-illustration.svg
client/static/images/light-logo.png
client/static/images/logo-avangard.png
client/static/images/logo-opoveshcheniiaavito.png
client/static/images/pilot-logo.png
client/static/images/urus.jpg
client/static/lock.jpg
client/static/lock.png
client/static/v.png
client/static/vuetify-logo.svg
client/store/activity.js
client/store/admin.js
client/store/app/index.js
client/store/app/mutations.js
client/store/arrival.js
client/store/car.js
client/store/credit.js
client/store/dashboard.js
client/store/fare.js
client/store/operator.js
client/store/order.js
client/store/permission.js
client/store/property.js
client/store/shipment.js
client/store/showroom.js
client/store/site.js
client/store/transit.js
client/store/usedcar.js
client/store/user.js
client/stylelint.config.js
client/utils/exportExcel.js
client/utils/index.js
composer.json
composer.lock
config/activitylog.php
config/app.php
config/auth.php
config/authentication-log.php
config/broadcasting.php
config/cache.php
config/cors.php
config/database.php
config/excel.php
config/filesystems.php
config/geoip.php
config/gravitel.php
config/hashing.php
config/image.php
config/logging.php
config/mail.php
config/mango.php
config/query-builder.php
config/queue.php
config/sanctum.php
config/scout.php
config/services.php
config/session.php
config/telegram.php
config/user-activity.php
config/view.php
config/websockets.php
database/.gitignore
database/factories/UserFactory.php
database/migrations/0000_00_00_000000_create_websockets_statistics_entries_table.php
database/migrations/2014_10_12_000000_create_users_table.php
database/migrations/2014_10_12_100000_create_password_resets_table.php
database/migrations/2019_08_19_000000_create_failed_jobs_table.php
database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php
database/migrations/2020_11_20_100001_create_log_table.php
database/migrations/2022_07_28_132616_create_activity_log_table.php
database/migrations/2022_09_22_163130_create_permission_tables.php
database/migrations/2022_09_23_174632_create_bouncer_tables.php
database/migrations/2023_08_30_181808_create_authentication_log_table.php
database/migrations/2023_10_04_143228_create_last_request_time_table.php
database/migrations/2024_12_06_151306_create_passed_orders_table.php
database/migrations/2025_01_21_180005_create_blacklist_orders_table.php
database/migrations/2026_08_31_090000_create_mango_calls_tables.php
database/seeders/DatabaseSeeder.php
package-lock.json
package.json
phpunit.xml
public/.htaccess
public/atlant-logo.png
public/avrora-logo.svg
public/favicon.ico
public/icon.png
public/images/atlant-logo.png
public/images/autru.png
public/images/avatars/avatar1.svg
public/images/avatars/avatar10.svg
public/images/avatars/avatar11.svg
public/images/avatars/avatar12.svg
public/images/avatars/avatar13.svg
public/images/avatars/avatar14.svg
public/images/avatars/avatar15.svg
public/images/avatars/avatar16.svg
public/images/avatars/avatar17.svg
public/images/avatars/avatar18.svg
public/images/avatars/avatar19.svg
public/images/avatars/avatar2.svg
public/images/avatars/avatar20.svg
public/images/avatars/avatar3.svg
public/images/avatars/avatar4.svg
public/images/avatars/avatar5.svg
public/images/avatars/avatar6.svg
public/images/avatars/avatar7.svg
public/images/avatars/avatar8.svg
public/images/avatars/avatar9.svg
public/images/avrora-logo.svg
public/images/background-user.jpg
public/images/fares/7d71bb26707c9cc52152643e4ff7d08f.jpg
public/images/fares/ec3eccea2411eb46d0d3dc85344ca561.jpg
public/images/fares/tiggo2pro34_1674472629.jpg
public/images/fares/tiggo2pro35_1674309311.jpg
public/images/fares/tiggo2pro35_1674309344.jpg
public/images/fares/tiggo2pro35_1674472629.jpg
public/images/fares/tiggo2pro35_1674472723.jpg
public/images/fares/tiggo2pro36_1674309311.jpg
public/images/fares/tiggo2pro36_1674309344.jpg
public/images/fares/tiggo2pro36_1674472629.jpg
public/images/fares/tiggo2pro36_1674472723.jpg
public/images/illustrations/404-illustration.svg
public/images/illustrations/500-illustration.svg
public/images/illustrations/maintenance-illustration.svg
public/images/illustrations/signin-illustration.svg
public/images/light-logo.png
public/images/lock.jpg
public/images/logo-avangard.png
public/images/logo-opoveshcheniiaavito.png
public/images/pilot-logo.png
public/images/urus.jpg
public/index.php
public/light-logo.png
public/lock.jpg
public/lock.png
public/logo-avangard.png
public/pilot-logo.png
public/robots.txt
public/urus.jpg
public/v.png
public/vuetify-logo.svg
resources/css/app.css
resources/js/app.js
resources/js/bootstrap.js
resources/lang/en/auth.php
resources/lang/en/pagination.php
resources/lang/en/passwords.php
resources/lang/en/validation.php
resources/views/vendor/authentication-log/.gitkeep
resources/views/vendor/authentication-log/emails/failed.blade.php
resources/views/vendor/authentication-log/emails/new.blade.php
resources/views/welcome.blade.php
routes/api.php
routes/channels.php
routes/console.php
routes/web.php
server.php
storage/app/.gitignore
storage/app/public/.gitignore
storage/clockwork/.gitignore
storage/framework/.gitignore
storage/framework/cache/.gitignore
storage/framework/cache/data/.gitignore
storage/framework/sessions/.gitignore
storage/framework/testing/.gitignore
storage/framework/views/.gitignore
storage/logs/.gitignore
tests/CreatesApplication.php
tests/Feature/ApiBenchmarkTest.php
tests/Feature/ExampleTest.php
tests/TestCase.php
tests/Unit/ExampleTest.php
tests/Unit/Mango/MangoCallDataTest.php
webpack.mix.js
```
