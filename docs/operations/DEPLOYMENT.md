# Развёртывание CRM V2 (Ubuntu + HestiaCP)

Документ описывает подготовку сервера для текущего контура: заявки, API сайта, приём событий Mango VPBX,
прослушивание записей и обновления в реальном времени (Reverb). Все адреса, пользователи и пути ниже —
**заполнители**: `crm.example.ru`, пользователь Hestia `crmuser`, каталог `/home/crmuser/web/crm.example.ru`.
Реальные конфигурации сервера и шаблоны Hestia этим документом не заменяются — фрагменты добавляются
вручную после сверки с текущими файлами.

## 1. Требования

- **PHP 8.3+** (CLI и PHP-FPM одной версии; `composer.json`: `php ^8.3`).
- Расширения из зависимостей (`composer.lock`): `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`,
  `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`; плюс `pdo_mysql` (MySQL), `zip`
  (Composer). Для `queue:work --timeout` нужен **`pcntl`** в CLI (без него зависшее задание не прерывается).
- **`allow_url_fopen = On`** в PHP-FPM. Прослушивание записей идёт потоком из Mango через HTTP-клиент Laravel
  (Guzzle): потоковые запросы Guzzle выполняет обработчиком на PHP-потоках, который требует `allow_url_fopen`.
  Без него CRM не начинает воспроизведение и показывает ошибку (код `stream_unsupported`), а не скачивает
  запись целиком.
- **MySQL 8**, отдельная база и пользователь с правами только на неё.
- **Node.js 22** (только для сборки фронтенда; на сервере можно собирать или привозить готовый `public/build`).
- **Supervisor** для постоянных процессов, **cron** для планировщика.
- Redis **не нужен**: очереди, кеш и сессии работают на драйвере `database`; Reverb работает в одном процессе
  (горизонтальное масштабирование Reverb через Redis не включено). Horizon не используется.

## 2. Переменные окружения (production)

`.env` создаётся на сервере вручную и в репозиторий не попадает. Пример без секретов:

```dotenv
APP_NAME="CRM"
APP_ENV=production
APP_KEY=                       # сгенерировать один раз: php artisan key:generate; дальше НЕ менять
APP_DEBUG=false
APP_URL=https://crm.example.ru

APP_LOCALE=ru
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=crm_v2
DB_USERNAME=crm_v2
DB_PASSWORD=               # задаётся на сервере

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=90
BROADCAST_CONNECTION=reverb

# Reverb: ключи генерируются один раз (любые случайные строки; APP_ID — число/строка).
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
# Где слушает сам Reverb — только локальный интерфейс, наружу порт не открывается.
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
# Публичный адрес WebSocket для браузера (через Nginx, WSS на 443).
REVERB_HOST=crm.example.ru
REVERB_PORT=443
REVERB_SCHEME=https
# Внутренний адрес, по которому приложение публикует события в Reverb (минуя Nginx).
REVERB_INTERNAL_HOST=127.0.0.1
REVERB_INTERNAL_PORT=8080
REVERB_INTERNAL_SCHEME=http
# Разрешённые Origin браузера (хосты через запятую).
REVERB_ALLOWED_ORIGINS=crm.example.ru

VITE_APP_NAME="${APP_NAME}"
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Важно:

- `VITE_*` попадают в собранный фронтенд **во время `npm run build`**: после их изменения нужна пересборка.
  `REVERB_APP_SECRET` в `VITE_*` не указывать.
- `APP_KEY` хранить в резервной копии отдельно от базы. Смена ключа делает недоступными зашифрованные секреты
  подключений (ключи Mango, ключи источников) и разлогинивает всех.
- Права на `.env`: владелец — пользователь сайта, `chmod 640`.

## 3. Первичная установка и обновление

Выполнять от пользователя сайта в корне приложения (`/home/crmuser/web/crm.example.ru/app` — пример).
Корень сайта (document root) в Hestia должен указывать на **`public/`** приложения, а не на корень проекта.

```bash
# 0. Резервная копия базы ПЕРЕД миграциями (каждое обновление)
mysqldump --single-transaction --routines --triggers -u crm_v2 -p crm_v2 | gzip > ~/backups/crm_v2_$(date +%F_%H%M).sql.gz

# 1. Режим обслуживания на время обновления (не для первичной установки)
php artisan down --retry=30

# 2. Код (git pull / распаковка архива), затем зависимости
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build                 # Vite + Wayfinder; результат — public/build

# 3. Миграции БЕЗ сброса (никогда migrate:fresh / migrate:refresh / db:wipe на рабочей базе)
php artisan migrate --force

# 4. Справочники и права: сидеры только добавляют недостающее, настройки не перезаписывают
php artisan db:seed --force

# 5. Первичная установка: первый администратор (пароль вводится скрыто)
php artisan app:create-admin  # только один раз

# 6. Права каталогов
chmod -R ug+rwX storage bootstrap/cache

# 7. Кеши Laravel
php artisan optimize          # config, routes, events, views

# 8. Перезапуск постоянных процессов (новый код подхватывается только после перезапуска)
php artisan queue:restart
php artisan reverb:restart
php artisan up
```

`php artisan storage:link` не требуется: публичных файлов в `storage` нет. SSR-сервер Inertia не нужен:
без собранного SSR-бандла страницы отрисовываются в браузере.

## 4. Постоянные процессы (Supervisor)

Очереди разделены, чтобы рассылка сигналов не задерживала приём звонков и заявок:

| Очередь | Что делает | Повторы |
|---|---|---|
| `mango-events` | события Mango → звонки, заявки, ответивший | 5 попыток, паузы 10 с – 30 мин; затем «Ошибка» |
| `site-requests` | заявки с сайта → заявки CRM, распределение | 5 попыток, затем «Ошибка» |
| `broadcasts` | сигналы реального времени в Reverb | 3 попытки, паузы 5/30 с, таймаут 15 с |
| `default` | прочие задания Laravel | по умолчанию |

`--timeout=60` меньше `retry_after` (90 с, `DB_QUEUE_RETRY_AFTER`): задание не выдаётся второму обработчику,
пока первый его выполняет. Задания Mango и сайта работают только с базой (без внешних запросов) и укладываются
в таймаут с запасом.

Файл `/etc/supervisor/conf.d/crm-v2.conf` (пример; пути и пользователь — заполнители):

```ini
[program:crm-v2-mango]
command=/usr/bin/php8.3 /home/crmuser/web/crm.example.ru/app/artisan queue:work database --queue=mango-events --sleep=1 --timeout=60 --max-time=3600
user=crmuser
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/home/crmuser/web/crm.example.ru/app/storage/logs/worker-mango.log

[program:crm-v2-site]
command=/usr/bin/php8.3 /home/crmuser/web/crm.example.ru/app/artisan queue:work database --queue=site-requests --sleep=1 --timeout=60 --max-time=3600
user=crmuser
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/home/crmuser/web/crm.example.ru/app/storage/logs/worker-site.log

[program:crm-v2-broadcasts]
command=/usr/bin/php8.3 /home/crmuser/web/crm.example.ru/app/artisan queue:work database --queue=broadcasts --sleep=1 --timeout=30 --max-time=3600
user=crmuser
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=40
redirect_stderr=true
stdout_logfile=/home/crmuser/web/crm.example.ru/app/storage/logs/worker-broadcasts.log

[program:crm-v2-default]
command=/usr/bin/php8.3 /home/crmuser/web/crm.example.ru/app/artisan queue:work database --queue=default --sleep=3 --timeout=60 --max-time=3600
user=crmuser
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/home/crmuser/web/crm.example.ru/app/storage/logs/worker-default.log

[program:crm-v2-reverb]
command=/usr/bin/php8.3 /home/crmuser/web/crm.example.ru/app/artisan reverb:start --host=127.0.0.1 --port=8080
user=crmuser
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=10
minfds=10000
redirect_stderr=true
stdout_logfile=/home/crmuser/web/crm.example.ru/app/storage/logs/reverb.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status 'crm-v2-*'
```

Логи процессов попадают в `storage/logs` и в репозиторий не добавляются; настройте ротацию (logrotate).

## 5. Планировщик (cron)

Одна строка в crontab пользователя сайта (`crontab -e` от `crmuser` или «Cron» в Hestia):

```cron
* * * * * cd /home/crmuser/web/crm.example.ru/app && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

Задачи (`routes/console.php`, все с защитой от наложения `withoutOverlapping`):

- `site-requests:process --older-than=300` — каждые 5 минут: принятые заявки сайта, которые очередь не обработала.
- `mango-events:process --older-than=300` — каждые 5 минут: принятые события Mango, которые очередь не обработала.
- `orders:distribute --limit=50` — каждую минуту: ожидающие заявки обратного звонка; заявка с закончившимся
  бизнес-периодом помечается «период закончился» и не назначается.

Записи в состоянии «Ошибка» автоматически не повторяются: `site-requests:retry-failed` и
`mango-events:retry-failed` запускаются вручную после устранения причины. Проверка: `php artisan schedule:list`.

## 6. Nginx: WSS-прокси для Reverb (HestiaCP)

Reverb слушает только `127.0.0.1:8080`; браузер подключается к `wss://crm.example.ru/app/{key}` через Nginx
сайта. Путь публикации `/apps/...` наружу не проксируется — приложение публикует события напрямую на
внутренний адрес. Порт 8080 в файрволе не открывать.

В HestiaCP шаблоны домена не правятся: используется пользовательский include, который подключают стандартные
шаблоны в блоке `server` для SSL. Сверьте наличие строки `include .../nginx.ssl.conf_*;` в текущем шаблоне
домена, затем создайте файл `/home/crmuser/conf/web/crm.example.ru/nginx.ssl.conf_reverb`:

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Если в Hestia включён прокси Nginx → Apache, файл добавляется к Nginx (как выше), Apache не меняется.
HTTPS: сертификат Let's Encrypt в Hestia для домена, принудительный редирект на HTTPS. Разрешённые Origin
WebSocket задаются `REVERB_ALLOWED_ORIGINS` (только домен CRM).

## 7. Реальное время: что получает сотрудник

- Только личный приватный канал `App.Models.User.{id}`; подписка — после авторизации на сервере (активный
  сотрудник, только свой канал). Клиентские события через Reverb отключены.
- Сигналы содержат только идентификаторы и версию; данные страница запрашивает обычным запросом с проверкой
  прав. Получатели вычисляются в момент отправки: отключённый сотрудник или потерявший право новых сигналов
  не получает, даже с открытым соединением.
- Сигналы публикуются после фиксации транзакции; сбой Reverb или очереди `broadcasts` не откатывает заявку,
  обработку Mango или назначение (ошибка пишется в лог «Realtime signal was not queued.»).
- После переподключения страницы перечитывают актуальные данные; уведомления — без звука и без запросов
  разрешений браузера.

## 8. Настройка Mango в личном кабинете

1. В CRM: «Подключения» → подключение Mango → «Приём событий»: скопировать «Адрес внешней системы» вида
   `https://crm.example.ru/api/mango/{ключ адреса}/` (Mango сам добавляет `events/call`, `events/summary`,
   `events/recording`, `events/record/added`).
2. В ЛК Mango (раздел интеграций / API-коннектор): указать этот адрес как адрес внешней системы; ключ и соль
   API из ЛК внести в подключение CRM.
3. В ЛК Mango **включить выдачу ссылок на записи разговоров по API** (по умолчанию выключено, 3.5.2) и запись
   разговоров на нужных линиях/сотрудниках.
4. В CRM привязать линии к источникам и внутренние номера сотрудников к пользователям.

## 9. Проверка на сервере

До фактического выполнения на сервере все пункты имеют статус **«Ожидает проверки на сервере»**.

| № | Сценарий | Ожидаемый результат | Статус |
|---|---|---|---|
| 1 | Форма сайта → API → заявка → оператор → уведомление | Заявка создана, назначена по графику, оператору пришло уведомление «Вам назначена заявка №…», список обновился без перезагрузки | Ожидает проверки на сервере |
| 2 | Повтор запроса с тем же `Idempotency-Key` | Та же квитанция, дубля заявки нет | Ожидает проверки на сервере |
| 3 | Запрос без `Idempotency-Key` | Принят как отдельный запрос | Ожидает проверки на сервере |
| 4 | Входящий в Mango → линия → заявка → ответивший | Заявка в салоне линии, ответственный — ответивший сотрудник | Ожидает проверки на сервере |
| 5 | Звонок клиента с уже назначенным ответственным | Ответственный не меняется | Ожидает проверки на сервере |
| 6 | Повтор вебхука Mango | Дублей событий, звонков и заявок нет | Ожидает проверки на сервере |
| 7 | Прослушивание записи и перемотка | Запись играет из Mango, перемотка работает | Ожидает проверки на сервере |
| 8 | Чужая заявка / чужая запись по прямому адресу | 403/404, сигналов о чужих заявках нет | Ожидает проверки на сервере |
| 9 | Переназначение открытой заявки | Новый ответственный получает уведомление; у прежнего открытая карточка закрывается с сообщением | Ожидает проверки на сервере |
| 10 | Перезапуск обработчиков и Reverb (`supervisorctl restart 'crm-v2-*'`) | Очереди дорабатывают задания; страницы переподключаются и перечитывают данные | Ожидает проверки на сервере |

Полезные команды диагностики: `php artisan queue:failed`, `php artisan schedule:list`,
`sudo supervisorctl status 'crm-v2-*'`, `tail -f storage/logs/reverb.log`.
