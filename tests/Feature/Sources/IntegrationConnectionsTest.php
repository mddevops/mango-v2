<?php

namespace Tests\Feature\Sources;

use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Models\IntegrationLine;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\SourceSiteIntake;
use App\Support\PermissionCatalog as P;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

class IntegrationConnectionsTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const KEY = 'test-key-value-0001';

    private const SALT = 'test-salt-value-0002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_secrets_are_encrypted_hidden_and_only_flagged(): void
    {
        $showroom = Showroom::factory()->create();
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('connections.store'), [
                'name' => 'Mango основной',
                'showroom_ids' => [$showroom->id],
                'api_key' => self::KEY,
                'api_salt' => self::SALT,
            ])
            ->assertSessionHasNoErrors();

        $connection = IntegrationConnection::query()->sole();
        $raw = (string) DB::table('integration_connections')->where('id', $connection->id)->value('secrets');
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertStringNotContainsString(self::SALT, $raw);
        $this->assertSame(['api_key' => self::KEY, 'api_salt' => self::SALT], $connection->secrets);
        $this->assertSame(IntegrationConnection::UNVERIFIED, $connection->verification_status);
        $this->assertArrayNotHasKey('secrets', $connection->toArray());

        $response = $this->actingAs($admin)->get(route('connections.index'));
        $response->assertInertia(fn (Assert $page) => $page
            ->component('connections/index')
            ->where('connections.data.0.secrets', ['api_key' => true, 'api_salt' => true])
            ->where('connections.data.0.verification_status', 'unverified'));
        $response->assertDontSee(self::KEY)->assertDontSee(self::SALT);

        $log = AuditLog::query()->where('event', 'integration_connection.created')->sole();
        $this->assertSame('API key: задан; API salt: задан', $log->changes['credentials']);
        $this->assertStringNotContainsString(self::KEY, json_encode($log->changes, JSON_UNESCAPED_UNICODE));
    }

    public function test_empty_field_keeps_secret_and_replace_and_clear_are_explicit(): void
    {
        $showroom = Showroom::factory()->create();
        $connection = $this->makeConnection([$showroom], secrets: ['api_key' => self::KEY, 'api_salt' => self::SALT]);
        $admin = $this->administrator();

        $payload = fn (array $overrides = []) => [
            'name' => 'Mango основной',
            'showroom_ids' => [$showroom->id],
            'api_key' => '',
            'api_salt' => '',
            'lock_version' => $connection->fresh()->lock_version,
            ...$overrides,
        ];

        $this->actingAs($admin)->put(route('connections.update', $connection), $payload(['name' => 'Mango новый']))->assertSessionHasNoErrors();
        $this->assertSame(['api_key' => self::KEY, 'api_salt' => self::SALT], $connection->fresh()->secrets);

        $this->actingAs($admin)->put(route('connections.update', $connection), $payload(['api_key' => 'replaced-key']))->assertSessionHasNoErrors();
        $this->assertSame(['api_key' => 'replaced-key', 'api_salt' => self::SALT], $connection->fresh()->secrets);

        $this->actingAs($admin)->put(route('connections.update', $connection), $payload(['clear_secrets' => ['api_salt']]))->assertSessionHasNoErrors();
        $this->assertSame(['api_key' => 'replaced-key'], $connection->fresh()->secrets);
        $this->assertSame(['api_key' => true, 'api_salt' => false], $connection->fresh()->secretPresence());

        $descriptions = AuditLog::query()->where('event', 'integration_connection.updated')->orderBy('id')->get()->pluck('changes')->all();
        $this->assertSame('API key: заменён', $descriptions[1]['credentials']);
        $this->assertSame('API salt: удалён', $descriptions[2]['credentials']);
        $this->assertStringNotContainsString('replaced-key', json_encode($descriptions, JSON_UNESCAPED_UNICODE));
    }

    public function test_secrets_are_not_flashed_back_on_validation_error(): void
    {
        $this->actingAs($this->administrator())
            ->post(route('connections.store'), ['name' => '', 'showroom_ids' => [], 'api_key' => self::KEY, 'api_salt' => self::SALT])
            ->assertSessionHasErrors(['name', 'showroom_ids'])
            ->assertSessionMissing('_old_input.api_key')
            ->assertSessionMissing('_old_input.api_salt');
    }

    public function test_only_full_administrator_manages_connections(): void
    {
        $showroom = Showroom::factory()->create();
        $connection = $this->makeConnection([$showroom]);

        $role = Role::query()->create(['name' => 'integrator', 'label' => 'Интегратор', 'guard_name' => 'web']);
        $role->syncPermissions([P::CONNECTIONS_MANAGE, P::CONNECTIONS_SECRETS, P::SOURCES_VIEW, P::SOURCES_MANAGE]);
        $integrator = $this->employee('integrator', attributes: ['has_global_access' => true]);
        $localAdmin = $this->employee('administrator', [$showroom]);

        foreach ([$integrator, $localAdmin] as $user) {
            $this->actingAs($user)->get(route('connections.index'))->assertForbidden();
            $this->actingAs($user)
                ->post(route('connections.store'), ['name' => 'Взлом', 'showroom_ids' => [$showroom->id]])
                ->assertForbidden();
            $this->actingAs($user)
                ->patch(route('connections.status', $connection), ['active' => false, 'lock_version' => 0])
                ->assertForbidden();
        }

        $this->assertTrue($connection->fresh()->active);
        $this->assertSame(1, IntegrationConnection::query()->count());
    }

    public function test_interface_gets_connection_access_from_the_server_check(): void
    {
        $showroom = Showroom::factory()->create();
        $role = Role::query()->create(['name' => 'integrator', 'label' => 'Интегратор', 'guard_name' => 'web']);
        $role->syncPermissions([P::WORKSPACE_VIEW, P::CONNECTIONS_MANAGE, P::SOURCES_VIEW, P::SOURCES_MANAGE]);
        $integrator = $this->employee('integrator', [$showroom], ['has_global_access' => true]);

        $this->actingAs($integrator)
            ->get(route('sources.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains(P::CONNECTIONS_MANAGE))
                ->where('auth.abilities.manageConnections', false)
                ->where('can.manageConnections', false));
        $this->actingAs($integrator)->get(route('connections.index'))->assertForbidden();

        $this->actingAs($this->administrator())
            ->get(route('sources.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.abilities.manageConnections', true)
                ->where('can.manageConnections', true));
    }

    public function test_secrets_need_separate_permission(): void
    {
        $showroom = Showroom::factory()->create();
        $admin = $this->administrator();
        $this->role('administrator')->revokePermissionTo(P::CONNECTIONS_SECRETS);

        $this->actingAs($admin->fresh())
            ->post(route('connections.store'), ['name' => 'Mango', 'showroom_ids' => [$showroom->id], 'api_key' => self::KEY])
            ->assertForbidden();

        $this->actingAs($admin->fresh())
            ->post(route('connections.store'), ['name' => 'Mango', 'showroom_ids' => [$showroom->id]])
            ->assertSessionHasNoErrors();
    }

    public function test_one_account_serves_several_sources_and_lines_are_unique_per_account(): void
    {
        $first = Showroom::factory()->create();
        $second = Showroom::factory()->create();
        $shared = $this->makeConnection([$first, $second], secrets: ['api_key' => self::KEY]);
        $otherAccount = $this->makeConnection([$first], 'Mango второй');
        $siteA = $this->makeSource($first);
        $siteB = $this->makeSource($second);
        $siteC = $this->makeSource($first);
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->put(route('sources.update', $siteA), $this->updateSourcePayload($siteA, ['connections' => [
                'site_intake' => true,
                'mango_connection_id' => $shared->id,
                'mango_lines' => [
                    ['type' => 'phone', 'value' => '8 (999) 123-45-67'],
                    ['type' => 'sip', 'value' => 'Sales01@vpbx.mangosip.ru'],
                ],
            ]]))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('sources.update', $siteB), $this->updateSourcePayload($siteB, ['connections' => [
                'site_intake' => false,
                'mango_connection_id' => $shared->id,
                'mango_lines' => [['type' => 'phone', 'value' => '+7 999 123 45 67']],
            ]]))
            ->assertSessionHasErrors(['connections.mango_lines.0.value' => 'Эта линия уже привязана к другому источнику в этом подключении.']);

        $this->actingAs($admin)
            ->put(route('sources.update', $siteB), $this->updateSourcePayload($siteB, ['connections' => [
                'site_intake' => false,
                'mango_connection_id' => $shared->id,
                'mango_lines' => [['type' => 'phone', 'value' => '+7 999 765 43 21'], ['type' => 'sip', 'value' => '79991234567']],
            ]]))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('sources.update', $siteC), $this->updateSourcePayload($siteC, ['connections' => [
                'site_intake' => false,
                'mango_connection_id' => $otherAccount->id,
                'mango_lines' => [['type' => 'phone', 'value' => '89991234567']],
            ]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $shared->bindings()->count());
        $this->assertTrue(SourceSiteIntake::query()->where('source_id', $siteA->id)->sole()->active);
        $sip = IntegrationLine::query()->where('type', 'sip')->where('value', 'Sales01@vpbx.mangosip.ru')->sole();
        $this->assertSame('sales01@vpbx.mangosip.ru', $sip->match_key);
        $this->assertSame('79991234567', IntegrationLine::query()->where('type', 'sip')->where('integration_connection_id', $shared->id)->where('value', '79991234567')->sole()->match_key);
        $this->assertSame('79991234567', IntegrationLine::query()->where('value', '8 (999) 123-45-67')->sole()->match_key);

        $page = $this->actingAs($admin)->get(route('sources.index'));
        $page->assertDontSee(self::KEY);
        $page->assertInertia(fn (Assert $p) => $p->where('can.manageConnections', true)->has('mangoConnections', 2));

        $log = AuditLog::query()->where('event', 'source.updated')->where('subject_id', $siteA->id)->sole();
        $this->assertSame(['не настроен', 'включён'], $log->changes['site_intake']);
        $this->assertSame([null, 'Mango основной'], $log->changes['mango_connection']);
    }

    public function test_duplicate_lines_in_one_form_are_rejected(): void
    {
        $showroom = Showroom::factory()->create();
        $connection = $this->makeConnection([$showroom]);
        $source = $this->makeSource($showroom);

        $this->actingAs($this->administrator())
            ->put(route('sources.update', $source), $this->updateSourcePayload($source, ['connections' => [
                'site_intake' => false,
                'mango_connection_id' => $connection->id,
                'mango_lines' => [['type' => 'phone', 'value' => '+79991112233'], ['type' => 'phone', 'value' => '8 999 111 22 33'], ['type' => 'sip', 'value' => 'a b']],
            ]]))
            ->assertSessionHasErrors(['connections.mango_lines.1.value', 'connections.mango_lines.2.value']);
    }

    public function test_connection_must_allow_source_showroom(): void
    {
        $allowed = Showroom::factory()->create();
        $other = Showroom::factory()->create();
        $connection = $this->makeConnection([$allowed]);
        $source = $this->makeSource($other);
        $bound = $this->makeSource($allowed);
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->put(route('sources.update', $source), $this->updateSourcePayload($source, ['connections' => [
                'site_intake' => false, 'mango_connection_id' => $connection->id, 'mango_lines' => [],
            ]]))
            ->assertSessionHasErrors('connections.mango_connection_id');

        $this->actingAs($admin)
            ->put(route('sources.update', $bound), $this->updateSourcePayload($bound, ['connections' => [
                'site_intake' => false, 'mango_connection_id' => $connection->id, 'mango_lines' => [],
            ]]))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('connections.update', $connection), [
                'name' => $connection->name,
                'showroom_ids' => [$other->id],
                'lock_version' => $connection->fresh()->lock_version,
            ])
            ->assertSessionHasErrors('showroom_ids');

        $this->actingAs($admin)
            ->put(route('sources.update', $bound), $this->updateSourcePayload($bound, ['showroom_id' => $other->id]))
            ->assertSessionHasErrors('showroom_id');

        $this->assertSame($allowed->id, $bound->fresh()->showroom_id);
    }

    public function test_unbinding_removes_lines_and_disabling_intake_keeps_row(): void
    {
        $showroom = Showroom::factory()->create();
        $connection = $this->makeConnection([$showroom]);
        $source = $this->makeSource($showroom);
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->put(route('sources.update', $source), $this->updateSourcePayload($source, ['connections' => [
                'site_intake' => true, 'mango_connection_id' => $connection->id, 'mango_lines' => [['type' => 'phone', 'value' => '+79990000001']],
            ]]))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('sources.update', $source), $this->updateSourcePayload($source, ['connections' => [
                'site_intake' => false, 'mango_connection_id' => null, 'mango_lines' => [],
            ]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, IntegrationLine::query()->count());
        $this->assertSame(0, $connection->bindings()->count());
        $this->assertFalse(SourceSiteIntake::query()->sole()->active);
    }

    public function test_stale_connection_form_is_rejected(): void
    {
        $showroom = Showroom::factory()->create();
        $connection = $this->makeConnection([$showroom]);
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->patch(route('connections.status', $connection), ['active' => false, 'lock_version' => 0])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('connections.update', $connection), ['name' => 'Новое', 'showroom_ids' => [$showroom->id], 'lock_version' => 0])
            ->assertSessionHasErrors('lock_version');
    }
}
