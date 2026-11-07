<?php

namespace App\Models;

use App\Enums\DistributionState;
use App\Enums\OrderOrigin;
use App\Models\Concerns\HasLockVersion;
use App\Observers\OrderObserver;
use App\Support\OrderReasonType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The work of one showroom with one client. Showroom and source are fixed at creation;
 * the business date and its rule are a snapshot that later edits and showroom settings
 * do not recalculate. `repeat_number` is the count of earlier orders of the same client
 * in the same showroom at creation time.
 *
 * `updated_at` changes only when the order itself is changed: viewing, history and
 * comments do not touch it, because the future call matching picks the last changed order.
 *
 * @property int $id
 * @property int $client_id
 * @property int $showroom_id
 * @property int $source_id
 * @property int $contact_channel_id
 * @property int|null $payment_method_id
 * @property OrderOrigin $origin
 * @property string|null $contact_name
 * @property string $phone the main phone, normalized («79515346783»); additional ones are in order_phones
 * @property int $order_status_id
 * @property int|null $responsible_id
 * @property DistributionState|null $distribution_state
 * @property CarbonImmutable|null $callback_at
 * @property CarbonImmutable|null $arrival_at
 * @property int|null $visit_result_id
 * @property string|null $car_interest
 * @property int|null $order_trash_reason_id
 * @property int|null $order_drop_reason_id
 * @property string|null $reason_comment
 * @property CarbonImmutable $event_at
 * @property CarbonImmutable $received_at
 * @property string $business_date
 * @property array{timezone: string, boundary: string|null} $business_day_rule
 * @property int $repeat_number
 * @property int $lock_version
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client $client
 * @property-read Showroom $showroom
 * @property-read Source $source
 * @property-read ContactChannel $channel
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read VisitResult|null $visitResult
 * @property-read OrderStatus $status
 * @property-read User|null $responsible
 * @property-read User|null $creator
 * @property-read OrderTrashReason|null $trashReason
 * @property-read OrderDropReason|null $dropReason
 * @property-read OrderTransfer|null $transferOut
 * @property-read OrderTransfer|null $transferIn
 * @property-read SiteCallbackRequest|null $siteRequest
 */
#[ObservedBy([OrderObserver::class])]
class Order extends Model
{
    use HasLockVersion;

    protected $fillable = [
        'client_id', 'showroom_id', 'source_id', 'contact_channel_id', 'payment_method_id', 'origin', 'contact_name', 'phone',
        'order_status_id', 'responsible_id', 'callback_at', 'arrival_at', 'visit_result_id', 'car_interest',
        'order_trash_reason_id', 'order_drop_reason_id', 'reason_comment',
        'event_at', 'received_at', 'business_date', 'business_day_rule', 'repeat_number', 'created_by',
    ];

    protected $attributes = [
        'repeat_number' => 0,
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'showroom_id' => 'integer',
            'source_id' => 'integer',
            'contact_channel_id' => 'integer',
            'payment_method_id' => 'integer',
            'origin' => OrderOrigin::class,
            'order_status_id' => 'integer',
            'responsible_id' => 'integer',
            'distribution_state' => DistributionState::class,
            'callback_at' => 'datetime',
            'arrival_at' => 'datetime',
            'visit_result_id' => 'integer',
            'order_trash_reason_id' => 'integer',
            'order_drop_reason_id' => 'integer',
            'event_at' => 'datetime',
            'received_at' => 'datetime',
            'business_day_rule' => 'array',
            'repeat_number' => 'integer',
            'lock_version' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function reasonType(): ?OrderReasonType
    {
        return match (true) {
            $this->order_trash_reason_id !== null => OrderReasonType::Trash,
            $this->order_drop_reason_id !== null => OrderReasonType::Drop,
            default => null,
        };
    }

    public function reasonId(): ?int
    {
        return $this->order_trash_reason_id ?? $this->order_drop_reason_id;
    }

    public function reason(): OrderTrashReason|OrderDropReason|null
    {
        return $this->trashReason ?? $this->dropReason;
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Showroom, $this>
     */
    public function showroom(): BelongsTo
    {
        return $this->belongsTo(Showroom::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<ContactChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(ContactChannel::class, 'contact_channel_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<VisitResult, $this>
     */
    public function visitResult(): BelongsTo
    {
        return $this->belongsTo(VisitResult::class);
    }

    /**
     * @return BelongsTo<OrderStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'order_status_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<OrderTrashReason, $this>
     */
    public function trashReason(): BelongsTo
    {
        return $this->belongsTo(OrderTrashReason::class, 'order_trash_reason_id');
    }

    /**
     * @return BelongsTo<OrderDropReason, $this>
     */
    public function dropReason(): BelongsTo
    {
        return $this->belongsTo(OrderDropReason::class, 'order_drop_reason_id');
    }

    /**
     * Only the additional numbers; the main one is `phone`.
     *
     * @return HasMany<OrderPhone, $this>
     */
    public function additionalPhones(): HasMany
    {
        return $this->hasMany(OrderPhone::class)->orderBy('position');
    }

    /**
     * All contact numbers of the order, the main one first without a name.
     *
     * @return list<array{phone: string, name: string|null}>
     */
    public function contactPhones(): array
    {
        return [
            ['phone' => $this->phone, 'name' => null],
            ...$this->additionalPhones->map(fn (OrderPhone $phone) => ['phone' => $phone->phone, 'name' => $phone->name])->values()->all(),
        ];
    }

    /**
     * @return HasMany<OrderComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(OrderComment::class);
    }

    /**
     * @return HasMany<Call, $this>
     */
    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    /**
     * The site request this order was created from.
     *
     * @return HasOne<SiteCallbackRequest, $this>
     */
    public function siteRequest(): HasOne
    {
        return $this->hasOne(SiteCallbackRequest::class);
    }

    /**
     * The transfer that took this order to another showroom.
     *
     * @return HasOne<OrderTransfer, $this>
     */
    public function transferOut(): HasOne
    {
        return $this->hasOne(OrderTransfer::class, 'source_order_id');
    }

    /**
     * The transfer this order was created by.
     *
     * @return HasOne<OrderTransfer, $this>
     */
    public function transferIn(): HasOne
    {
        return $this->hasOne(OrderTransfer::class, 'target_order_id');
    }
}
