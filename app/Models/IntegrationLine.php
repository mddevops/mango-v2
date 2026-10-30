<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone number or SIP address of an account bound to a source. SIP addresses stay
 * strings; match_key is the normalized form the account-wide unique index uses.
 *
 * @property int $id
 * @property int $integration_connection_source_id
 * @property int $integration_connection_id
 * @property string $type
 * @property string $value
 * @property string $match_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class IntegrationLine extends Model
{
    public const PHONE = 'phone';

    public const SIP = 'sip';

    public const TYPES = [self::PHONE, self::SIP];

    protected $fillable = ['integration_connection_source_id', 'integration_connection_id', 'type', 'value', 'match_key'];

    protected function casts(): array
    {
        return [
            'integration_connection_source_id' => 'integer',
            'integration_connection_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnectionSource, $this>
     */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnectionSource::class, 'integration_connection_source_id');
    }

    public static function matchKey(string $type, string $value): string
    {
        if ($type === self::PHONE) {
            $digits = preg_replace('/\D+/', '', $value) ?? '';

            if (strlen($digits) === 11 && $digits[0] === '8') {
                return '7'.substr($digits, 1);
            }

            return strlen($digits) === 10 ? '7'.$digits : $digits;
        }

        return mb_strtolower(trim($value));
    }
}
