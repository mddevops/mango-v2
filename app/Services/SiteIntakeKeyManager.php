<?php

namespace App\Services;

use App\Models\Source;
use App\Models\SourceSiteIntake;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issues, replaces and revokes the API key of a source's site intake. The key is
 * random, belongs to one source and is returned only once; the database keeps its
 * SHA-256 hash and last characters, the audit only the fact of the change.
 */
class SiteIntakeKeyManager
{
    private const PREFIX = 'crm_';

    public function __construct(private readonly AuditLogger $audit) {}

    public function issue(Source $source): string
    {
        return DB::transaction(function () use ($source) {
            $intake = $this->intake($source);

            if ($source->transfer_from_showroom_id !== null) {
                throw ValidationException::withMessages(['key' => 'Служебный источник передачи между салонами не принимает обращения с сайта.']);
            }

            $replaced = $intake->key_hash !== null;
            $key = self::PREFIX.Str::random(48);

            $intake->forceFill([
                'key_hash' => hash('sha256', $key),
                'key_hint' => substr($key, -4),
                'key_issued_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit->record('source.site_key_issued', 'source', $source->id, $source->name, $replaced ? 'Заменён ключ API сайта' : 'Выпущен ключ API сайта', [
                'site_key' => $replaced ? 'заменён' : 'выпущен',
            ], [$source->showroom_id]);

            return $key;
        });
    }

    public function revoke(Source $source): void
    {
        DB::transaction(function () use ($source) {
            $intake = $this->intake($source);

            if ($intake->key_hash === null) {
                return;
            }

            $intake->forceFill(['key_hash' => null, 'key_hint' => null, 'key_issued_at' => null])->save();

            $this->audit->record('source.site_key_revoked', 'source', $source->id, $source->name, 'Отозван ключ API сайта', [
                'site_key' => 'отозван',
            ], [$source->showroom_id]);
        });
    }

    private function intake(Source $source): SourceSiteIntake
    {
        /** @var SourceSiteIntake|null $intake */
        $intake = SourceSiteIntake::query()->where('source_id', $source->id)->lockForUpdate()->first();

        if ($intake === null) {
            throw ValidationException::withMessages(['key' => 'Сначала включите «Приём заявок с сайта» в настройках источника.']);
        }

        return $intake;
    }
}
