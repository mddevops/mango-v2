<?php

namespace Tests\Feature\Mango\Concerns;

use App\Models\IntegrationConnection;
use App\Models\IntegrationConnectionSource;
use App\Models\IntegrationLine;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\TelephonyExtension;
use App\Models\User;
use App\Support\IntegrationProvider;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures follow the documented examples of MangoOffice_VPBX_API v1.9 (3.1.2, 3.1.6 and
 * «Примеры поведения»): routing from IVR to extension 123, consultative transfer,
 * a callback started by a command, summaries of answered and missed calls. Only the
 * times are moved to the test day; key and salt are test values.
 */
trait BuildsMangoCalls
{
    protected const CLIENT = '79515346783';

    protected const LINE = '74951234567';

    protected function mangoConnection(Showroom $showroom, string $name = 'Mango основной', string $key = 'test-vpbx-key', string $salt = 'test-vpbx-salt'): IntegrationConnection
    {
        $connection = new IntegrationConnection(['provider' => IntegrationProvider::MangoVpbx, 'name' => $name]);
        $connection->secrets = ['api_key' => $key, 'api_salt' => $salt];
        $connection->save();
        $connection->showrooms()->sync([$showroom->id]);

        return $connection->fresh();
    }

    protected function bindLine(IntegrationConnection $connection, Source $source, string $value, string $type = IntegrationLine::PHONE): IntegrationLine
    {
        $binding = IntegrationConnectionSource::query()->firstOrCreate(
            ['integration_connection_id' => $connection->id, 'source_id' => $source->id],
            ['provider' => IntegrationProvider::MangoVpbx],
        );

        return IntegrationLine::create([
            'integration_connection_source_id' => $binding->id,
            'integration_connection_id' => $connection->id,
            'type' => $type,
            'value' => $value,
            'match_key' => IntegrationLine::matchKey($type, $value),
        ]);
    }

    /**
     * @param  list<Showroom>  $showrooms
     * @param  list<string>  $permissions
     */
    protected function mangoEmployee(string $name, array $showrooms, array $permissions = [P::ORDERS_VIEW, P::ORDERS_UPDATE], array $attributes = []): User
    {
        $role = 'mango_'.md5(implode(',', $permissions));
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web'], ['label' => $role])->syncPermissions($permissions);

        return $this->employee($role, $showrooms, ['name' => $name, ...$attributes]);
    }

    protected function extension(IntegrationConnection $connection, string $extension, User $user): TelephonyExtension
    {
        return TelephonyExtension::create(['integration_connection_id' => $connection->id, 'extension' => $extension, 'user_id' => $user->id]);
    }

    protected function at(int $seconds = 0): int
    {
        return CarbonImmutable::parse('2026-10-19 12:00:00', 'Europe/Moscow')->getTimestamp() + $seconds;
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function leg(string $entry, string $callId, int $seq, string $state, int $at, array $from, array $to, array $extra = []): array
    {
        return [
            'entry_id' => $entry,
            'call_id' => $callId,
            'timestamp' => $at,
            'seq' => $seq,
            'call_state' => $state,
            'location' => 'abonent',
            'from' => $from,
            'to' => $to,
            ...$extra,
        ];
    }

    /**
     * An incoming call from IVR routed to an extension (route example of «Примеры
     * поведения»): the IVR leg, then the employee leg taken from it.
     *
     * @return list<array<string, mixed>>
     */
    protected function routedCall(string $entry, string $extension = '123', int $offset = 0, bool $answered = true, string $client = self::CLIENT, string $line = self::LINE): array
    {
        $ivrFrom = ['number' => $client];
        $ivrTo = ['number' => $line, 'line_number' => $line];
        $legFrom = ['number' => $client, 'taken_from_call_id' => "{$entry}:ivr"];
        $legTo = ['extension' => $extension, 'number' => 'sip:aaa@mangosip.ru', 'line_number' => $line];
        $route = ['command_id' => 'c111'];

        return array_values(array_filter([
            $this->leg($entry, "{$entry}:ivr", 1, 'Appeared', $this->at($offset), $ivrFrom, $ivrTo, ['location' => 'ivr']),
            $this->leg($entry, "{$entry}:ivr", 2, 'Disconnected', $this->at($offset + 2), $ivrFrom, $ivrTo, ['location' => 'ivr', 'disconnect_reason' => 1100]),
            $this->leg($entry, "{$entry}:abonent", 1, 'Appeared', $this->at($offset + 3), $legFrom, $legTo, $route),
            $answered ? $this->leg($entry, "{$entry}:abonent", 2, 'Connected', $this->at($offset + 12), $legFrom, $legTo, $route) : null,
            $this->leg($entry, "{$entry}:abonent", $answered ? 3 : 2, 'Disconnected', $this->at($offset + 42), $legFrom, $legTo, [...$route, 'disconnect_reason' => $answered ? 1120 : 1110]),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(string $entry, ?string $extension = '123', int $offset = 0, array $overrides = []): array
    {
        $answered = $extension !== null;

        return [
            'entry_id' => $entry,
            'call_direction' => 1,
            'from' => ['number' => self::CLIENT],
            'to' => $answered ? ['extension' => $extension, 'number' => 'sip:aaa@mangosip.ru'] : [],
            'line_number' => self::LINE,
            'create_time' => $this->at($offset),
            'forward_time' => $this->at($offset + 3),
            'talk_time' => $answered ? $this->at($offset + 12) : 0,
            'end_time' => $this->at($offset + 42),
            'entry_result' => $answered ? 1 : 0,
            'disconnect_reason' => 1100,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function mangoEvent(IntegrationConnection $connection, string $path, array $data, string $key = 'test-vpbx-key', string $salt = 'test-vpbx-salt'): TestResponse
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->mangoPost($connection->webhook_key, $path, $key, $json, hash('sha256', $key.$json.$salt));
    }

    /**
     * An ordinary form body, parsed by PHP the same way as in production.
     */
    protected function mangoPost(?string $webhookKey, string $path, string $key, string $json, string $sign): TestResponse
    {
        $fields = ['vpbx_api_key' => $key, 'sign' => $sign, 'json' => $json];

        return $this->call('POST', "/api/mango/{$webhookKey}/events/{$path}", $fields, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    }
}
