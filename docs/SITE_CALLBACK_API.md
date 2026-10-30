# API сайта: приём обратного звонка

Серверное API для отправки формы обратного звонка с сайта в CRM. Вызывается **только с сервера сайта**: ключ не
размещается в JavaScript страницы. Domain, Origin, Referer и переданный `ip_address` не заменяют авторизацию.

## Подключение источника

1. В «Источниках» у источника сайта включите «Приём заявок с сайта».
2. В действии «API сайта» (полный администратор с правом управления подключениями) посмотрите адрес приёма и
   `source_id`, выпустите ключ (право на секреты подключений). Ключ показывается **один раз**; в CRM хранится
   только его хеш. Замена ключа сразу отключает прежний, отзыв — прекращает приём до выпуска нового.
3. Ключ привязан к одному источнику: с ключом источника A нельзя отправить обращение от имени источника B.

Источник должен быть активен и не быть внутренним источником передачи между салонами; салон источника — активен;
тип заявки «Обратный звонок» — активен и привязан к источнику. Иначе ответ `403 intake_unavailable`.

## Запрос

```
POST {BASE_URL}/api/v1/callback-requests
Authorization: Bearer {SITE_API_KEY}
Idempotency-Key: {IDEMPOTENCY_KEY}        (необязательно)
Content-Type: application/json             (или application/x-www-form-urlencoded)
Accept: application/json
```

- `Authorization: Bearer` обязателен; ключ передаётся только в заголовке, не в адресе и не в теле. `source_id`
  должен принадлежать этому ключу.
- Тело — JSON-объект или обычная HTML-форма (`application/x-www-form-urlencoded`), не больше 64 КБ. Оба формата
  проверяются и обрабатываются одинаково; числа из формы (`source_id`, id автомобилей) приходят строками и
  принимаются как числа.
- Минимальное тело: `source_id` и `phone`, все остальные поля необязательны.

| Поле              | Обязательно | Формат                                                                    |
| ----------------- | ----------- | ------------------------------------------------------------------------- |
| `source_id`       | да          | целое число — id источника из окна «API сайта»                            |
| `phone`           | да          | российский номер в любом обычном написании; хранится как `7XXXXXXXXXX`    |
| `name`            | нет         | до 150 символов                                                           |
| `comment`         | нет         | до 5000 символов; сохраняется отдельным комментарием автора «Сайт»        |
| `ip_address`      | нет         | IPv4 или IPv6 посетителя                                                  |
| `url`             | нет         | точка входа, только `http`/`https`, до 2048 символов                      |
| `utm_source` … `utm_term` | нет | `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`; до 255 символов |
| `form`            | нет         | название формы, до 100 символов                                           |
| `created_at`      | нет         | момент отправки формы, ISO 8601 с часовым поясом                          |
| `viewed_vehicles` | нет         | объект с разделами `catalog`, `used`, `special_offers`                    |

Поля `inner_id`, `page_url`, `form_name`, `vehicle_interest`, `referrer` не поддерживаются. Салон, ответственный,
статус, клиент, признак повтора, бизнес-дата и происхождение заявки задаёт CRM — такие поля в теле отклоняются
(`422`). Салон берётся из источника, тип заявки — «Обратный звонок».

### `created_at`

- Формат: `2026-10-23T11:58:00+03:00` или `2026-10-23T08:58:00Z` (часовой пояс обязателен).
- Не позже чем через 5 минут от времени сервера CRM.
- Определяет время обращения и бизнес-дату по правилу салона. Если поле не передано — используется время приёма.

### `viewed_vehicles`

```json
{ "catalog": [101, 102], "used": [5531], "special_offers": [] }
```

В обычной форме — массивами полей (не JSON-строкой):

```
viewed_vehicles[catalog][]=101&viewed_vehicles[catalog][]=102&viewed_vehicles[used][]=5531
```

- Каждый раздел необязателен; значения — положительные целые id автомобилей на сайте.
- Не больше 100 id в разделе; повторы удаляются.
- В карточке заявки показываются как номера по разделам; ссылки на сайт не строятся.

## Ответы

| Код   | `error`                | Когда                                                                     |
| ----- | ---------------------- | ------------------------------------------------------------------------- |
| `202` | —                      | `{"receipt_id": "…", "status": "accepted"}` — обращение надёжно сохранено |
| `400` | `invalid_json`         | JSON-тело не является объектом                                            |
| `401` | `unauthorized`         | нет ключа, ключ неверный, отозван или принадлежит другому источнику       |
| `403` | `intake_unavailable`   | источник, салон, приём или тип «Обратный звонок» отключены                |
| `409` | `idempotency_conflict` | тот же `Idempotency-Key` уже использован с другими данными                |
| `413` | `payload_too_large`    | тело больше 64 КБ                                                         |
| `415` | `unsupported_media_type` | тело не JSON и не `application/x-www-form-urlencoded`                   |
| `422` | `validation_failed`    | ошибки полей или переданный, но пустой/некорректный `Idempotency-Key`: `{"error", "message", "errors": {"поле": ["…"]}}` |
| `429` | `too_many_requests`    | превышен лимит; см. заголовок `Retry-After`                               |
| `503` | `storage_failed`       | сохранить обращение не удалось — повторите позже                          |

`202` означает «принято и сохранено», а не «заявка создана»: заявка создаётся и распределяется в фоне.

Лимиты (на минуту): по IP отправителя — `SITE_API_IP_PER_MINUTE` (по умолчанию 300), по паре «источник + IP» —
`SITE_API_PER_MINUTE` (по умолчанию 60). За обратным прокси учитывается реальный IP только при настроенных
доверенных прокси.

## Повторы

`Idempotency-Key` **необязателен**. Для сайтов, которые мы контролируем, он **рекомендован**.

- **С ключом.** Ключ — новое значение для каждой отправки формы: от 8 до 100 символов из латиницы, цифр и
  `.` `_` `:` `-` (удобно использовать UUID). При сетевой ошибке или ответе `429`/`503`/`5xx` повторяйте **с тем
  же ключом и тем же телом**, включая тот же `created_at`: повтор возвращает тот же `receipt_id` и не создаёт
  вторую заявку. Тот же ключ с другими данными — `409`. Эквивалентные данные в JSON и в форме считаются одним
  запросом.
- **Без ключа.** Каждый HTTP-запрос — новое обращение со своей квитанцией, даже при совпадающих данных; запросы
  не объединяются. Поэтому сетевой повтор без ключа **может создать новую заявку**. API-токен ключ не заменяет:
  он подтверждает источник, а не отдельную отправку формы.
- Заголовок передан, но пустой или некорректный — `422`.
- Повтор обработки внутри CRM (повтор задания очереди, восстановление, ручной повтор) дубль не создаёт: одно
  сохранённое обращение даёт не больше одной заявки и одного комментария — с ключом и без него.
- Ответы `400`/`401`/`403`/`413`/`415`/`422` без исправления не повторяйте.

## Примеры

Минимальный запрос (Postman: Body → x-www-form-urlencoded, поля `source_id` и `phone`; Authorization → Bearer
Token):

```bash
curl -X POST "{BASE_URL}/api/v1/callback-requests" \
  -H "Authorization: Bearer {SITE_API_KEY}" \
  -H "Accept: application/json" \
  --data-urlencode "source_id={SOURCE_ID}" \
  --data-urlencode "phone=89000000000"
```

Обычная форма (`application/x-www-form-urlencoded`) с ключом и списками автомобилей:

```bash
curl -X POST "{BASE_URL}/api/v1/callback-requests" \
  -H "Authorization: Bearer {SITE_API_KEY}" \
  -H "Idempotency-Key: {IDEMPOTENCY_KEY}" \
  -H "Accept: application/json" \
  --data-urlencode "source_id={SOURCE_ID}" \
  --data-urlencode "phone=+7 900 000-00-00" \
  --data-urlencode "name=Имя клиента" \
  --data-urlencode "url=https://example.ru/catalog/" \
  --data-urlencode "created_at=2026-10-23T11:58:00+03:00" \
  --data-urlencode "viewed_vehicles[catalog][]=1" \
  --data-urlencode "viewed_vehicles[catalog][]=5" \
  --data-urlencode "viewed_vehicles[used][]=54" \
  --data-urlencode "viewed_vehicles[special_offers][]=24"
```

JSON:

```bash
curl -X POST "{BASE_URL}/api/v1/callback-requests" \
  -H "Authorization: Bearer {SITE_API_KEY}" \
  -H "Idempotency-Key: {IDEMPOTENCY_KEY}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "source_id": {SOURCE_ID},
    "phone": "+7 900 000-00-00",
    "name": "Имя клиента",
    "comment": "Перезвоните после 18:00",
    "url": "https://example.ru/catalog/",
    "form": "Обратный звонок в шапке",
    "utm_source": "yandex",
    "created_at": "2026-10-23T11:58:00+03:00",
    "viewed_vehicles": {"catalog": [101, 102], "used": [5531]}
  }'
```

Laravel HTTP client на сервере сайта:

```php
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$idempotencyKey = (string) Str::uuid();
$payload = [
    'source_id' => (int) config('services.crm.source_id'),
    'phone' => $request->input('phone'),
    'name' => $request->input('name'),
    'url' => $request->fullUrl(),
    'form' => 'Обратный звонок в шапке',
    'created_at' => now()->toIso8601String(),
];

// Ключ и тело сохраняются вместе с заявкой на стороне сайта и не меняются при повторах.
$response = Http::withToken(config('services.crm.key'))
    ->withHeaders(['Idempotency-Key' => $idempotencyKey])
    ->acceptJson()
    ->timeout(10)
    ->retry(3, 2000, fn ($exception) => $exception instanceof ConnectionException
        || in_array($exception->response?->status(), [429, 503], true), throw: false)
    ->post(config('services.crm.url').'/api/v1/callback-requests', $payload);

$receiptId = $response->status() === 202 ? $response->json('receipt_id') : null;
```

`{BASE_URL}`, `{SITE_API_KEY}`, `{SOURCE_ID}`, `{IDEMPOTENCY_KEY}` и `config('services.crm.*')` — заполнители: подставьте значения из
окна «API сайта». Не храните ключ в репозитории.

## Обработка в CRM

1. Запрос сохраняется в журнал обращений API (без заголовка `Authorization`, ключа и полного HTTP-запроса),
   ответ `202`.
2. После фиксации транзакции задание ставится в очередь `site-requests`. В одной транзакции создаются клиент (или
   находится существующий по телефону), заявка с происхождением «API сайта», начальным статусом, без
   ответственного, с временем и бизнес-датой из запроса; комментарий — отдельной записью автора «Сайт»; заявка
   связывается с обращением. Повторная обработка не создаёт вторую заявку.
3. Затем запускается обычное распределение. Если доступных операторов нет — это не ошибка: заявка ждёт
   распределения (`orders:distribute`).
4. Ошибка обработки повторяется до 5 раз с задержкой (30 с, 2 мин, 10 мин, 30 мин), затем обращение получает
   состояние «Ошибка» и видно в окне «API сайта» с кнопкой «Повторить».

## Команды

```bash
# обработчик очереди (постоянно запущенный процесс; QUEUE_CONNECTION=database)
php artisan queue:work --queue=site-requests

# восстановление: обращения, оставшиеся «Ожидает обработки» дольше 60 с (например, очередь была недоступна)
php artisan site-requests:process --limit=100 --older-than=60

# повтор обращений в состоянии «Ошибка» (все или одно)
php artisan site-requests:retry-failed --limit=100
php artisan site-requests:retry-failed --receipt={RECEIPT_ID}

# повтор распределения заявок без ответственного
php artisan orders:distribute --limit=50
```

Планировщик и постоянный обработчик очереди в рабочей среде пока не настроены: команды восстановления и
распределения нужно запускать по расписанию (например, раз в минуту), а `queue:work` — под супервизором.
