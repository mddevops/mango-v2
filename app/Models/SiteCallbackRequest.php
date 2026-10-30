<?php

namespace App\Models;

use App\Enums\SiteRequestState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A callback request accepted from a site through the common API. It is saved before
 * the site gets its receipt and keeps only the contract fields: neither the API key nor
 * the raw HTTP request. The event time, the business date and its rule are fixed at
 * acceptance; a queue delay or later showroom settings do not change them.
 *
 * `viewed_vehicles` maps a section (catalog, used, special_offers) to sorted ids of
 * vehicles on that site; they are not ids of CRM vehicles. `visitor_ip` is what the
 * site reported about its visitor: it authorizes nothing and is not shown to operators.
 *
 * @property int $id
 * @property string $receipt_id
 * @property int $source_id
 * @property int $showroom_id
 * @property int $contact_channel_id
 * @property string|null $idempotency_key null when the site sent no Idempotency-Key
 * @property string $fingerprint
 * @property string|null $name
 * @property string $phone
 * @property string|null $comment
 * @property string|null $visitor_ip
 * @property string|null $url
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_term
 * @property string|null $utm_content
 * @property string|null $form
 * @property array<string, list<int>>|null $viewed_vehicles
 * @property CarbonImmutable $event_at
 * @property bool $event_time_reported
 * @property CarbonImmutable $received_at
 * @property string $business_date
 * @property array{timezone: string, boundary: string|null} $business_day_rule
 * @property SiteRequestState $state
 * @property int|null $order_id
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempt_at
 * @property string|null $error_code
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Source $source
 * @property-read Showroom $showroom
 * @property-read Order|null $order
 */
class SiteCallbackRequest extends Model
{
    public const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public const VEHICLE_SECTIONS = [
        'catalog' => 'Каталог',
        'used' => 'С пробегом',
        'special_offers' => 'Спецпредложения',
    ];

    /** Safe error codes shown to administrators; never exception messages. */
    public const ERRORS = [
        'initial_status_missing' => 'Не выбран начальный статус заявок',
        'processing_error' => 'Внутренняя ошибка обработки',
        'not_queued' => 'Не удалось поставить в очередь',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'showroom_id' => 'integer',
            'contact_channel_id' => 'integer',
            'viewed_vehicles' => 'array',
            'event_at' => 'datetime',
            'event_time_reported' => 'boolean',
            'received_at' => 'datetime',
            'business_day_rule' => 'array',
            'state' => SiteRequestState::class,
            'order_id' => 'integer',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'receipt_id';
    }

    /**
     * @return array<string, string>
     */
    public function utm(): array
    {
        return array_filter(
            collect(self::UTM_FIELDS)->mapWithKeys(fn (string $field) => [$field => $this->getAttribute($field)])->all(),
            fn ($value) => $value !== null,
        );
    }

    public function errorLabel(): ?string
    {
        return $this->error_code === null ? null : (self::ERRORS[$this->error_code] ?? self::ERRORS['processing_error']);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<Showroom, $this>
     */
    public function showroom(): BelongsTo
    {
        return $this->belongsTo(Showroom::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
