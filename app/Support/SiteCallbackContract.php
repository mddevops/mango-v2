<?php

namespace App\Support;

use App\Models\SiteCallbackRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The body of `POST /api/v1/callback-requests`. Validates it, normalizes it and builds
 * the fingerprint a repeated delivery is compared by: the normalized contract, not the
 * JSON field order. The server receipt time is not part of it. Unknown fields are
 * neither validated nor stored; fields the CRM decides itself are rejected.
 *
 * @phpstan-type Normalized array{name: string|null, phone: string, comment: string|null, ip_address: string|null, url: string|null, utm_source: string|null, utm_medium: string|null, utm_campaign: string|null, utm_term: string|null, utm_content: string|null, form: string|null, created_at: CarbonImmutable|null, viewed_vehicles: array<string, list<int>>}
 */
final class SiteCallbackContract
{
    public const MAX_BYTES = 65536;

    public const MAX_VEHICLES = 100;

    public const MAX_FUTURE_MINUTES = 5;

    public const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9._:\-]{8,100}$/';

    /** Fields chosen by the CRM; sending them is a contract error, not a silent override. */
    private const CONTROLLED = [
        'showroom_id', 'responsible_id', 'order_status_id', 'status', 'status_id', 'client_id', 'order_id',
        'repeat_number', 'business_date', 'origin', 'contact_channel_id', 'channel', 'received_at', 'event_at',
    ];

    private const RENAMED = ['page_url' => 'url', 'form_name' => 'form'];

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+\-]\d{2}:?\d{2})$/';

    /**
     * @param  array<array-key, mixed>  $body
     */
    public static function validator(array $body): ValidatorContract
    {
        $rules = [
            'source_id' => ['required', 'integer:strict', 'min:1'],
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:50'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'ip_address' => ['nullable', 'string', 'ip'],
            'url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'form' => ['nullable', 'string', 'max:100'],
            'created_at' => ['nullable', 'string', 'max:40'],
            'viewed_vehicles' => ['nullable', 'array:'.implode(',', array_keys(SiteCallbackRequest::VEHICLE_SECTIONS))],
        ];

        foreach (SiteCallbackRequest::UTM_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:255'];
        }

        foreach (array_keys(SiteCallbackRequest::VEHICLE_SECTIONS) as $section) {
            $rules["viewed_vehicles.{$section}"] = ['nullable', 'list', 'max:'.self::MAX_VEHICLES];
            $rules["viewed_vehicles.{$section}.*"] = ['integer:strict', 'min:1', 'max:'.PHP_INT_MAX];
        }

        foreach (self::CONTROLLED as $field) {
            $rules[$field] = ['prohibited'];
        }

        foreach (array_keys(self::RENAMED) as $field) {
            $rules[$field] = ['prohibited'];
        }

        $messages = [
            'phone.required' => 'Укажите телефон клиента.',
            'url.url' => 'Укажите адрес страницы, начинающийся с http:// или https://.',
            'ip_address.ip' => 'Укажите IPv4- или IPv6-адрес посетителя.',
            'viewed_vehicles.array' => 'viewed_vehicles — объект с разделами catalog, used, special_offers.',
            'viewed_vehicles.*.list' => 'Раздел просмотренных автомобилей — массив идентификаторов.',
            'viewed_vehicles.*.max' => 'В разделе просмотренных автомобилей не больше '.self::MAX_VEHICLES.' идентификаторов.',
            'viewed_vehicles.*.*.integer' => 'Идентификатор автомобиля — целое положительное число.',
            'viewed_vehicles.*.*.min' => 'Идентификатор автомобиля — целое положительное число.',
        ];

        foreach (self::CONTROLLED as $field) {
            $messages["{$field}.prohibited"] = "Поле {$field} определяет CRM; клиент API его не передаёт.";
        }

        foreach (self::RENAMED as $field => $name) {
            $messages["{$field}.prohibited"] = "Поле {$field} не входит в контракт: используйте {$name}.";
        }

        return Validator::make($body, $rules, $messages)->after(function (ValidatorContract $validator) use ($body) {
            if (is_string($body['phone'] ?? null) && Phone::normalize($body['phone']) === null) {
                $validator->errors()->add('phone', 'Укажите полный российский номер: +7 и 10 цифр.');
            }

            $createdAt = $body['created_at'] ?? null;

            if (is_string($createdAt) && ! $validator->errors()->has('created_at')) {
                $moment = self::parseMoment($createdAt);

                if ($moment === null) {
                    $validator->errors()->add('created_at', 'Укажите время в формате ISO 8601 с часовым поясом, например 2026-10-23T14:30:00+03:00.');
                } elseif ($moment->gt(CarbonImmutable::now()->addMinutes(self::MAX_FUTURE_MINUTES))) {
                    $validator->errors()->add('created_at', 'Время обращения не может быть больше чем на '.self::MAX_FUTURE_MINUTES.' минут в будущем.');
                }
            }
        });
    }

    /**
     * A form body carries every value as a string. Whole positive numbers of `source_id`
     * and the vehicle ids become integers, so the form passes the same strict rules as
     * JSON and equal data gives an equal fingerprint. Anything else is left for the
     * validator to reject.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    public static function fromForm(array $body): array
    {
        $body['source_id'] = self::formInteger($body['source_id'] ?? null);

        if (is_array($body['viewed_vehicles'] ?? null)) {
            foreach ($body['viewed_vehicles'] as $section => $ids) {
                if (is_array($ids)) {
                    $body['viewed_vehicles'][$section] = array_map(self::formInteger(...), $ids);
                }
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Normalized
     */
    public static function normalize(array $validated): array
    {
        $vehicles = [];

        foreach (array_keys(SiteCallbackRequest::VEHICLE_SECTIONS) as $section) {
            $ids = array_map(intval(...), $validated['viewed_vehicles'][$section] ?? []);
            $ids = array_values(array_unique($ids));
            sort($ids);
            $vehicles[$section] = $ids;
        }

        $normalized = [
            'name' => self::text($validated['name'] ?? null),
            'phone' => (string) Phone::normalize((string) $validated['phone']),
            'comment' => self::text($validated['comment'] ?? null),
            'ip_address' => self::ip($validated['ip_address'] ?? null),
            'url' => self::text($validated['url'] ?? null),
            'form' => self::text($validated['form'] ?? null),
            'created_at' => is_string($validated['created_at'] ?? null) ? self::parseMoment($validated['created_at']) : null,
            'viewed_vehicles' => $vehicles,
        ];

        foreach (SiteCallbackRequest::UTM_FIELDS as $field) {
            $normalized[$field] = self::text($validated[$field] ?? null);
        }

        /** @var Normalized $normalized */
        return $normalized;
    }

    /**
     * @param  Normalized  $data
     */
    public static function fingerprint(int $sourceId, array $data): string
    {
        $values = ['source_id' => $sourceId, ...$data, 'created_at' => $data['created_at']?->utc()->format('Y-m-d\TH:i:s.u\Z')];
        ksort($values);

        return hash('sha256', (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Stored form of the viewed vehicles: only non-empty sections, null when none.
     *
     * @param  array<string, list<int>>  $vehicles
     * @return array<string, list<int>>|null
     */
    public static function storedVehicles(array $vehicles): ?array
    {
        $filled = array_filter($vehicles, fn (array $ids) => $ids !== []);

        return $filled === [] ? null : $filled;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function formInteger(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[1-9]\d{0,17}$/', $value) === 1 ? (int) $value : $value;
    }

    private static function ip(mixed $value): ?string
    {
        $value = self::text($value);
        $packed = $value === null ? false : @inet_pton($value);

        return $packed === false ? $value : (string) inet_ntop($packed);
    }

    private static function parseMoment(string $value): ?CarbonImmutable
    {
        if (preg_match(self::ISO_8601, $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
