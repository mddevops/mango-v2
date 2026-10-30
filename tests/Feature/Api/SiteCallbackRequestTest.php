<?php

namespace Tests\Feature\Api;

use App\Enums\DistributionState;
use App\Enums\SiteRequestState;
use App\Jobs\ProcessSiteCallbackRequest;
use App\Models\AuditLog;
use App\Models\ContactChannel;
use App\Models\DistributionSchedule;
use App\Models\Order;
use App\Models\OrderComment;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\SiteCallbackRequest;
use App\Models\Source;
use App\Models\SourceSiteIntake;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BusinessDayCalculator;
use App\Services\CallbackDistributor;
use App\Services\OrderManager;
use App\Services\SiteIntakeKeyManager;
use App\Services\SiteRequestProcessor;
use App\Support\PermissionCatalog as P;
use Carbon\CarbonImmutable;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * 2026-10-19 is a Monday. Showrooms are in Moscow (UTC+3) with the 19:00 boundary.
 */
class SiteCallbackRequestTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const URL = '/api/v1/callback-requests';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator(['name' => 'Админ']);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:00', 'Europe/Moscow'));
    }

    public function test_accepted_request_is_saved_and_becomes_an_unassigned_callback_order_with_site_context(): void
    {
        [$source, $key] = $this->siteSource();

        $response = $this->send($key, [
            'source_id' => $source->id,
            'name' => 'Александр',
            'phone' => '8 951 534-67-83',
            'comment' => '<b>Интересует</b> Kia Sportage',
            'ip_address' => '203.0.113.10',
            'url' => 'https://auto-sever.ru/catalog/kia/sportage',
            'utm_source' => 'yandex',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'sportage',
            'utm_term' => 'купить kia sportage',
            'utm_content' => 'banner_1',
            'form' => 'callback',
            'created_at' => '2026-10-19T11:58:00+03:00',
            'viewed_vehicles' => ['catalog' => [8, 1, 5, 1], 'used' => [654, 54], 'special_offers' => [1]],
            'unknown_field' => 'не сохраняется',
        ])->assertStatus(202);

        $request = SiteCallbackRequest::query()->sole();
        $response->assertExactJson(['receipt_id' => $request->receipt_id, 'status' => 'accepted']);
        $this->assertSame(26, strlen($request->receipt_id));

        $order = Order::query()->with(['status', 'channel'])->sole();
        $this->assertSame(SiteRequestState::Processed, $request->state);
        $this->assertSame($order->id, $request->order_id);
        $this->assertSame('site_api', $order->origin->value);
        $this->assertSame(ContactChannel::CALLBACK, $order->channel->code);
        $this->assertTrue($order->status->is_initial);
        $this->assertNull($order->responsible_id);
        $this->assertNull($order->created_by);
        $this->assertSame(DistributionState::Pending, $order->distribution_state, 'Without operators the order waits in «Не назначены».');
        $this->assertSame('Александр', $order->contact_name);
        $this->assertSame('79515346783', $order->phone);
        $this->assertSame(0, $order->additionalPhones()->count());
        $this->assertSame('2026-10-19 08:58:00', $order->event_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 09:00:00', $order->received_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19', $order->business_date);
        $this->assertEquals(['catalog' => [1, 5, 8], 'used' => [54, 654], 'special_offers' => [1]], $request->viewed_vehicles);

        $comment = OrderComment::query()->sole();
        $this->assertNull($comment->author_id);
        $this->assertSame('Сайт', $comment->author_name);
        $this->assertSame('<b>Интересует</b> Kia Sportage', $comment->body);

        $created = AuditLog::query()->where('event', 'order.created')->sole();
        $this->assertSame([AuditLog::ACTOR_SYSTEM, 'site_api'], [$created->actor_kind, $created->basis]);
        $this->assertStringNotContainsString('203.0.113.10', (string) json_encode(AuditLog::query()->pluck('changes')));

        $this->actingAs($this->admin)->get(route('orders.show', $order->id))
            ->assertDontSee('203.0.113.10')
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.origin', 'API сайта')
                ->where('order.site_request.url', 'https://auto-sever.ru/catalog/kia/sportage')
                ->where('order.site_request.form', 'callback')
                ->where('order.site_request.utm.utm_term', 'купить kia sportage')
                ->where('order.site_request.viewed_vehicles', [
                    ['section' => 'catalog', 'label' => 'Каталог', 'ids' => [1, 5, 8]],
                    ['section' => 'used', 'label' => 'С пробегом', 'ids' => [54, 654]],
                    ['section' => 'special_offers', 'label' => 'Спецпредложения', 'ids' => [1]],
                ]));
    }

    public function test_scheduled_operator_gets_the_order_and_the_assignment_is_audited(): void
    {
        [$source, $key] = $this->siteSource();
        $operator = $this->operator($source->showroom);

        $this->send($key, $this->body($source))->assertStatus(202);

        $order = Order::query()->sole();
        $this->assertSame($operator->id, $order->responsible_id);
        $this->assertNull($order->distribution_state);
        $assigned = AuditLog::query()->where('event', 'order.responsible_changed')->sole();
        $this->assertSame('distribution', $assigned->basis);
    }

    public function test_key_opens_only_its_own_source(): void
    {
        [$first, $firstKey] = $this->siteSource();
        [$second, $secondKey] = $this->siteSource();

        $this->postJson(self::URL, $this->body($first), ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(401)->assertJsonPath('error', 'unauthorized');
        $this->send('crm_wrong', $this->body($first))->assertStatus(401);
        $this->send($firstKey, $this->body($second))->assertStatus(401);
        $this->send($secondKey, $this->body($first))->assertStatus(401);
        $this->send($firstKey, $this->body($first, ['source_id' => (string) $first->id]))->assertStatus(422)->assertJsonValidationErrors('source_id');
        $this->assertSame(0, SiteCallbackRequest::query()->count());

        $replaced = app(SiteIntakeKeyManager::class)->issue($first);
        $this->send($firstKey, $this->body($first))->assertStatus(401);
        $this->send($replaced, $this->body($first))->assertStatus(202);

        app(SiteIntakeKeyManager::class)->revoke($first);
        $this->send($replaced, $this->body($first))->assertStatus(401);
        $this->assertSame(1, SiteCallbackRequest::query()->count());
    }

    public function test_disabled_source_showroom_intake_or_channel_and_internal_source_reject_new_requests(): void
    {
        $cases = [
            'source' => fn (Source $source) => $source->forceFill(['active' => false])->save(),
            'showroom' => fn (Source $source) => $source->showroom->forceFill(['active' => false])->save(),
            'intake' => fn (Source $source) => SourceSiteIntake::query()->where('source_id', $source->id)->update(['active' => false]),
            'channel not allowed' => fn (Source $source) => $source->channels()->sync($this->channels('direct-call')),
            'channel disabled' => fn () => ContactChannel::query()->where('code', ContactChannel::CALLBACK)->update(['active' => false]),
            'internal' => fn (Source $source) => $source->forceFill(['transfer_from_showroom_id' => Showroom::factory()->create()->id])->save(),
        ];

        foreach ($cases as $case => $disable) {
            [$source, $key] = $this->siteSource();
            $disable($source);

            $this->send($key, $this->body($source))->assertStatus(403)->assertJsonPath('error', 'intake_unavailable');

            ContactChannel::query()->where('code', ContactChannel::CALLBACK)->update(['active' => true]);
            $this->assertSame(0, SiteCallbackRequest::query()->count(), $case);
        }

        $internal = $this->makeSource(Showroom::factory()->create());
        $internal->forceFill(['transfer_from_showroom_id' => Showroom::factory()->create()->id])->save();
        SourceSiteIntake::create(['source_id' => $internal->id]);
        $this->actingAs($this->admin)->postJson(route('sources.site-intake.key', $internal))->assertStatus(422);
    }

    public function test_contract_validation(): void
    {
        [$source, $key] = $this->siteSource();
        $invalid = [
            'phone' => ['phone' => '+7 951 534'],
            'phone_missing' => ['phone' => null],
            'showroom_id' => ['showroom_id' => 1],
            'responsible_id' => ['responsible_id' => $this->admin->id],
            'order_status_id' => ['order_status_id' => 1],
            'client_id' => ['client_id' => 1],
            'page_url' => ['page_url' => 'https://example.ru'],
            'form_name' => ['form_name' => 'callback'],
            'url' => ['url' => 'javascript:alert(1)'],
            'ip_address' => ['ip_address' => '999.1.1.1'],
            'created_at' => ['created_at' => '2026-10-19T11:00:00'],
            'viewed_vehicles' => ['viewed_vehicles' => ['stock' => [1]]],
            'viewed_vehicles.catalog' => ['viewed_vehicles' => ['catalog' => range(1, 101)]],
            'viewed_vehicles.used.0' => ['viewed_vehicles' => ['used' => ['5']]],
            'viewed_vehicles.special_offers.0' => ['viewed_vehicles' => ['special_offers' => [0]]],
            'name' => ['name' => str_repeat('я', 151)],
        ];

        foreach ($invalid as $field => $overrides) {
            $body = array_filter($this->body($source, $overrides), fn ($value) => $value !== null);
            $this->send($key, $body)->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonValidationErrors(explode('_missing', $field)[0]);
        }

        $this->send($key, $this->body($source, ['comment' => str_repeat('x', 70000)]))->assertStatus(413);
        $this->withHeaders(['Authorization' => "Bearer {$key}", 'Idempotency-Key' => (string) Str::uuid()])
            ->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'phone=1')
            ->assertStatus(415);
        $this->assertSame(0, SiteCallbackRequest::query()->count());

        $this->send($key, $this->body($source, ['ip_address' => '203.0.113.10']))->assertStatus(202);
        $this->send($key, $this->body($source, ['ip_address' => '2001:DB8::1']))->assertStatus(202);
        $this->assertEqualsCanonicalizing(['203.0.113.10', '2001:db8::1'], SiteCallbackRequest::query()->pluck('visitor_ip')->all());
    }

    public function test_phone_variants_are_one_client_and_a_new_key_creates_a_repeat_order(): void
    {
        [$source, $key] = $this->siteSource();

        $this->send($key, $this->body($source, ['phone' => '8 951 534-67-83']))->assertStatus(202);
        $this->send($key, $this->body($source, ['phone' => '+7 (951) 534-67-83']))->assertStatus(202);

        $orders = Order::query()->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame($orders[0]->client_id, $orders[1]->client_id);
        $this->assertSame([0, 1], $orders->pluck('repeat_number')->all());
    }

    public function test_event_time_business_day_and_future_limit(): void
    {
        [$source, $key] = $this->siteSource();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 19:10', 'Europe/Moscow'));

        $this->send($key, $this->body($source, ['created_at' => '2026-10-19T15:59:00Z']))->assertStatus(202);
        $this->send($key, $this->body($source))->assertStatus(202);
        $this->send($key, $this->body($source, ['created_at' => '2026-10-19T19:14:00+03:00']))->assertStatus(202);
        $this->send($key, $this->body($source, ['created_at' => '2026-10-19T19:16:00+03:00']))->assertStatus(422)->assertJsonValidationErrors('created_at');

        [$reported, $missing, $soon] = SiteCallbackRequest::query()->orderBy('id')->get()->all();
        $this->assertTrue($reported->event_time_reported);
        $this->assertSame('2026-10-19 15:59:00', $reported->event_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 16:10:00', $reported->received_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19', $reported->business_date);
        $this->assertFalse($missing->event_time_reported);
        $this->assertTrue($missing->event_at->equalTo($missing->received_at));
        $this->assertSame('2026-10-20', $missing->business_date);
        $this->assertSame('2026-10-20', $soon->business_date);
        $this->assertEquals(['timezone' => 'Europe/Moscow', 'boundary' => '19:00'], $reported->business_day_rule);
    }

    public function test_queue_delay_keeps_the_business_date_and_an_ended_period_is_left_for_a_manual_decision(): void
    {
        Queue::fake();
        [$source, $key] = $this->siteSource();
        $this->operator($source->showroom, [1, 2]);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 18:50', 'Europe/Moscow'));

        $this->send($key, $this->body($source))->assertStatus(202);
        Queue::assertPushedOn('site-requests', ProcessSiteCallbackRequest::class);
        $this->assertSame(0, Order::query()->count());

        $source->showroom->forceFill(['business_day_boundary_enabled' => false])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 19:30', 'Europe/Moscow'));
        app(SiteRequestProcessor::class)->process(SiteCallbackRequest::query()->sole()->id);

        $order = Order::query()->sole();
        $this->assertSame('2026-10-19', $order->business_date);
        $this->assertEquals(['timezone' => 'Europe/Moscow', 'boundary' => '19:00'], $order->business_day_rule);
        $this->assertNull($order->responsible_id);
        $this->assertSame(DistributionState::Expired, $order->distribution_state);
        $this->assertSame(SiteRequestState::Processed, SiteCallbackRequest::query()->sole()->state);
    }

    public function test_repeated_delivery_returns_the_receipt_and_other_data_under_the_key_is_a_conflict(): void
    {
        [$source, $key] = $this->siteSource();
        $idempotencyKey = (string) Str::uuid();
        $body = $this->body($source, [
            'comment' => 'Перезвоните',
            'created_at' => '2026-10-19T11:58:00+03:00',
            'viewed_vehicles' => ['catalog' => [3, 1, 3], 'used' => []],
        ]);

        $first = $this->send($key, $body, $idempotencyKey)->assertStatus(202)->json('receipt_id');

        $reordered = array_reverse($body, true);
        $reordered['viewed_vehicles'] = ['catalog' => [1, 3], 'special_offers' => []];
        $reordered['created_at'] = '2026-10-19T08:58:00Z';
        $reordered['url'] = '';
        $this->travelTo(CarbonImmutable::parse('2026-10-19 12:05', 'Europe/Moscow'));
        $this->send($key, $reordered, $idempotencyKey)->assertStatus(202)->assertExactJson(['receipt_id' => $first, 'status' => 'accepted']);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderComment::query()->count());
        $this->assertSame('2026-10-19 09:00:00', SiteCallbackRequest::query()->sole()->received_at->utc()->format('Y-m-d H:i:s'));

        $this->send($key, [...$body, 'comment' => 'Другой текст'], $idempotencyKey)->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');
        $this->send($key, [...$body, 'phone' => '+7 900 000-00-01'], $idempotencyKey)->assertStatus(409);
        $this->assertSame(1, SiteCallbackRequest::query()->count());
    }

    public function test_minimal_request_without_idempotency_key_is_accepted_and_every_request_is_new(): void
    {
        [$source, $key] = $this->siteSource();
        $minimal = ['source_id' => $source->id, 'phone' => '89515346783'];

        $first = $this->send($key, $minimal, null)->assertStatus(202)->json('receipt_id');
        $second = $this->send($key, $minimal, null)->assertStatus(202)->json('receipt_id');

        $this->assertNotSame($first, $second);
        $requests = SiteCallbackRequest::query()->orderBy('id')->get();
        $this->assertSame([null, null], $requests->pluck('idempotency_key')->all());
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, $requests->pluck('order_id')->unique()->count());
        $this->assertNull(Order::query()->first()->contact_name);
    }

    public function test_repeated_processing_of_a_request_without_key_creates_one_order_and_one_comment(): void
    {
        Queue::fake();
        [$source, $key] = $this->siteSource();
        $this->send($key, $this->body($source, ['comment' => 'Перезвоните']), null)->assertStatus(202);
        $id = SiteCallbackRequest::query()->sole()->id;

        $processor = app(SiteRequestProcessor::class);
        (new ProcessSiteCallbackRequest($id))->handle($processor);
        (new ProcessSiteCallbackRequest($id))->handle($processor);
        $processor->processPending(100, 0);
        $this->artisan('site-requests:retry-failed')->assertSuccessful();
        app(OrderManager::class)->createFromSiteRequest($id);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderComment::query()->count());
        $this->assertSame(SiteRequestState::Processed, SiteCallbackRequest::query()->sole()->state);
    }

    public function test_present_idempotency_key_must_be_valid(): void
    {
        [$source, $key] = $this->siteSource();

        foreach (['', '   ', 'short', 'с пробелом и кириллицей', str_repeat('a', 101)] as $invalid) {
            $this->send($key, $this->body($source), null, ['Idempotency-Key' => $invalid])
                ->assertStatus(422)->assertJsonValidationErrors('Idempotency-Key');
            $this->sendForm($key, 'source_id='.$source->id.'&phone=89515346783', $invalid)
                ->assertStatus(422)->assertJsonValidationErrors('Idempotency-Key');
        }

        $this->send($key, $this->body($source), 'order-2026-10-19:42')->assertStatus(202);
        $this->assertSame(1, SiteCallbackRequest::query()->count());
    }

    public function test_form_body_is_accepted_and_matches_equivalent_json_under_one_key(): void
    {
        [$source, $key] = $this->siteSource();
        $idempotencyKey = (string) Str::uuid();
        $form = 'source_id='.$source->id.'&phone=89515346783&name='.rawurlencode('Александр')
            .'&url='.rawurlencode('https://auto-sever.ru/catalog/').'&utm_source=yandex'
            .'&created_at='.rawurlencode('2026-10-19T11:58:00+03:00')
            .'&viewed_vehicles[catalog][]=1&viewed_vehicles[catalog][]=5&viewed_vehicles[used][]=54&viewed_vehicles[special_offers][]=24';

        $receipt = $this->sendForm($key, $form, $idempotencyKey)->assertStatus(202)->json('receipt_id');

        $request = SiteCallbackRequest::query()->sole();
        $this->assertEquals(['catalog' => [1, 5], 'used' => [54], 'special_offers' => [24]], $request->viewed_vehicles);
        $this->assertSame('79515346783', $request->phone);
        $this->assertSame(1, Order::query()->count());

        $this->send($key, [
            'source_id' => $source->id,
            'phone' => '+7 951 534-67-83',
            'name' => 'Александр',
            'url' => 'https://auto-sever.ru/catalog/',
            'utm_source' => 'yandex',
            'created_at' => '2026-10-19T08:58:00Z',
            'viewed_vehicles' => ['catalog' => [5, 1], 'used' => [54], 'special_offers' => [24]],
        ], $idempotencyKey)->assertStatus(202)->assertExactJson(['receipt_id' => $receipt, 'status' => 'accepted']);

        $this->sendForm($key, $form.'&comment=other', $idempotencyKey)->assertStatus(409)->assertJsonPath('error', 'idempotency_conflict');
        $this->sendForm($key, 'source_id='.$source->id.'&phone=89515346783&viewed_vehicles[catalog][]=x', null)
            ->assertStatus(422)->assertJsonValidationErrors('viewed_vehicles.catalog.0');
        $this->sendForm($key, 'source_id='.$source->id.'&phone=89515346783&viewed_vehicles='.rawurlencode('{"catalog":[1]}'), null)
            ->assertStatus(422)->assertJsonValidationErrors('viewed_vehicles');

        $this->assertSame(1, SiteCallbackRequest::query()->count());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_api_without_token_is_still_rejected(): void
    {
        [$source] = $this->siteSource();

        $this->postJson(self::URL, $this->body($source))->assertStatus(401)->assertJsonPath('error', 'unauthorized');
        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json'], 'source_id='.$source->id.'&phone=89515346783')
            ->assertStatus(401);
        $this->assertSame(0, SiteCallbackRequest::query()->count());
    }

    public function test_concurrent_identical_delivery_meets_at_the_unique_index(): void
    {
        [$source, $key] = $this->siteSource();
        $competitor = null;

        SiteCallbackRequest::creating(function (SiteCallbackRequest $request) use (&$competitor) {
            if ($competitor !== null) {
                return;
            }

            $competitor = Str::lower((string) Str::ulid());
            DB::table('site_callback_requests')->insert([...$request->getAttributes(), 'receipt_id' => $competitor]);
        });

        $this->send($key, $this->body($source))->assertStatus(202)->assertJsonPath('receipt_id', $competitor);
        $this->assertSame(1, SiteCallbackRequest::query()->count());
    }

    public function test_processing_again_never_duplicates_the_order_or_the_comment(): void
    {
        Queue::fake();
        [$source, $key] = $this->siteSource();
        $this->send($key, $this->body($source, ['comment' => 'Комментарий']))->assertStatus(202);
        $id = SiteCallbackRequest::query()->sole()->id;

        $order = app(OrderManager::class)->createFromSiteRequest($id);
        $this->assertSame($order?->id, app(OrderManager::class)->createFromSiteRequest($id)?->id);

        $processor = app(SiteRequestProcessor::class);
        (new ProcessSiteCallbackRequest($id))->handle($processor);
        (new ProcessSiteCallbackRequest($id))->handle($processor);
        $processor->processPending(100, 0);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame('79515346783', Order::query()->value('phone'));
        $this->assertSame(0, DB::table('order_phones')->count());
        $this->assertSame(1, OrderComment::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'order.created')->count());
        $this->assertSame(SiteRequestState::Processed, SiteCallbackRequest::query()->sole()->state);
    }

    public function test_request_saved_without_a_queued_job_is_recovered_by_the_command(): void
    {
        [$source, $key] = $this->siteSource();
        config(['queue.default' => 'broken', 'queue.connections.broken' => ['driver' => 'missing-driver']]);

        $this->send($key, $this->body($source))->assertStatus(202);

        $request = SiteCallbackRequest::query()->sole();
        $this->assertSame(SiteRequestState::Accepted, $request->state);
        $this->assertSame('not_queued', $request->error_code);
        $this->assertSame(0, Order::query()->count());

        $this->artisan('site-requests:process', ['--older-than' => 0])->assertSuccessful();

        $this->assertSame(SiteRequestState::Processed, $request->fresh()->state);
        $this->assertNull($request->fresh()->error_code);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_failure_after_the_order_was_created_continues_with_assignment_only(): void
    {
        Queue::fake();
        [$source, $key] = $this->siteSource();
        $operator = $this->operator($source->showroom);
        $this->send($key, $this->body($source))->assertStatus(202);
        $id = SiteCallbackRequest::query()->sole()->id;

        $this->failingDistribution();

        try {
            app(SiteRequestProcessor::class)->process($id);
            $this->fail('Distribution must fail.');
        } catch (RuntimeException) {
        }

        $request = SiteCallbackRequest::query()->sole();
        $this->assertSame(SiteRequestState::Accepted, $request->state);
        $this->assertSame('processing_error', $request->error_code);
        $this->assertNotNull($request->order_id);
        $this->assertNull(Order::query()->sole()->responsible_id);

        $this->app->forgetInstance(CallbackDistributor::class);
        $this->app->offsetUnset(CallbackDistributor::class);
        $this->app->forgetInstance(SiteRequestProcessor::class);
        app(SiteRequestProcessor::class)->process($id);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($operator->id, Order::query()->sole()->responsible_id);
        $this->assertSame(SiteRequestState::Processed, SiteCallbackRequest::query()->sole()->state);
    }

    public function test_attempts_are_limited_and_a_failed_request_is_retried_by_hand(): void
    {
        Queue::fake();
        [$source, $key] = $this->siteSource();
        $this->send($key, $this->body($source))->assertStatus(202);
        $request = SiteCallbackRequest::query()->sole();

        $this->failingDistribution();

        for ($attempt = 1; $attempt <= SiteRequestProcessor::MAX_ATTEMPTS; $attempt++) {
            try {
                app(SiteRequestProcessor::class)->process($request->id);
            } catch (RuntimeException) {
            }
        }

        $this->assertSame(SiteRequestState::Failed, $request->fresh()->state);
        $this->assertSame(SiteRequestProcessor::MAX_ATTEMPTS, $request->fresh()->attempts);

        $this->app->offsetUnset(CallbackDistributor::class);
        $this->app->forgetInstance(SiteRequestProcessor::class);

        $worker = $this->employeeWith('site_worker', [P::SOURCES_VIEW, P::ORDERS_VIEW], [$source->showroom]);
        $this->actingAs($worker)->postJson(route('site-requests.retry', $request->receipt_id))->assertForbidden();
        $this->actingAs($worker)->getJson(route('sources.site-intake.show', $source))->assertForbidden();

        Queue::fake();
        $this->actingAs($this->admin)->postJson(route('site-requests.retry', $request->receipt_id))->assertOk();
        $this->assertSame(SiteRequestState::Accepted, $request->fresh()->state);
        Queue::assertPushed(ProcessSiteCallbackRequest::class);

        $this->artisan('site-requests:process', ['--older-than' => 0])->assertSuccessful();
        $this->assertSame(SiteRequestState::Processed, $request->fresh()->state);
        $this->assertSame(1, AuditLog::query()->where('event', 'site_request.retried')->count());
    }

    public function test_only_a_full_administrator_manages_keys_and_secrets_never_leave_the_key_answer(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        SourceSiteIntake::create(['source_id' => $source->id]);

        $partial = $this->employeeWith('connections_only', [P::SOURCES_VIEW, P::CONNECTIONS_MANAGE, P::CONNECTIONS_SECRETS], [$showroom]);
        $this->actingAs($partial)->postJson(route('sources.site-intake.key', $source))->assertForbidden();
        $this->actingAs($partial)->getJson(route('sources.site-intake.show', $source))->assertForbidden();

        $key = $this->actingAs($this->admin)->postJson(route('sources.site-intake.key', $source))->assertOk()->json('key');
        $intake = SourceSiteIntake::query()->where('source_id', $source->id)->sole();
        $this->assertSame(hash('sha256', $key), $intake->key_hash);
        $this->assertSame(substr($key, -4), $intake->key_hint);

        $this->send($key, $this->body($source))->assertStatus(202);

        $this->actingAs($this->admin)->getJson(route('sources.site-intake.show', $source))
            ->assertOk()
            ->assertJsonPath('source_id', $source->id)
            ->assertJsonPath('endpoint', url(self::URL))
            ->assertJsonPath('intake.has_key', true)
            ->assertJsonPath('requests.0.state', 'processed')
            ->assertJsonPath('requests.0.order_id', Order::query()->sole()->id)
            ->assertDontSee($key)
            ->assertDontSee($intake->key_hash);
        $this->actingAs($this->admin)->get(route('sources.index'))->assertOk()->assertDontSee($key)->assertDontSee($intake->key_hash);

        $this->actingAs($this->admin)->deleteJson(route('sources.site-intake.revoke', $source))->assertOk();
        $this->assertNull($intake->fresh()->key_hash);

        $logs = AuditLog::query()->where('subject_type', 'source')->get();
        $this->assertSame(['source.site_key_issued', 'source.site_key_revoked'], $logs->pluck('event')->all());
        $this->assertStringNotContainsString($key, (string) json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString($intake->key_hash ?? hash('sha256', $key), (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_rate_limit_counts_the_source_and_the_actual_sender_ip(): void
    {
        config(['services.site_api.per_minute' => 2]);
        [$source, $key] = $this->siteSource();

        $this->send($key, $this->body($source, ['ip_address' => '198.51.100.1']))->assertStatus(202);
        $this->send($key, $this->body($source, ['ip_address' => '198.51.100.2']))->assertStatus(202);
        $this->send($key, $this->body($source, ['ip_address' => '198.51.100.3']))->assertStatus(429)->assertJsonPath('error', 'too_many_requests')->assertHeader('Retry-After');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2']);
        $this->send($key, $this->body($source))->assertStatus(202);

        [$other, $otherKey] = $this->siteSource();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->send($otherKey, $this->body($other))->assertStatus(202);
    }

    /**
     * @return array{0: Source, 1: string}
     */
    private function siteSource(): array
    {
        $showroom = Showroom::factory()->create([
            'timezone' => 'Europe/Moscow',
            'business_day_boundary_enabled' => true,
            'business_day_boundary' => '19:00',
        ]);
        $source = $this->makeSource($showroom, ['channel_ids' => $this->channels('callback-request', 'direct-call')]);
        SourceSiteIntake::create(['source_id' => $source->id]);

        return [$source->fresh(), app(SiteIntakeKeyManager::class)->issue($source)];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Source $source, array $overrides = []): array
    {
        return ['source_id' => $source->id, 'name' => 'Александр', 'phone' => '+7 951 534-67-83', ...$overrides];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $key, array $body, ?string $idempotencyKey = '', array $headers = []): TestResponse
    {
        $headers = ['Authorization' => "Bearer {$key}", ...$headers];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey === '' ? (string) Str::uuid() : $idempotencyKey;
        }

        return $this->postJson(self::URL, $body, $headers);
    }

    /**
     * An ordinary HTML-form body, parsed by PHP the same way as in production.
     */
    private function sendForm(string $key, string $content, ?string $idempotencyKey): TestResponse
    {
        parse_str($content, $parameters);
        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$key}"];

        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return $this->call('POST', self::URL, $parameters, [], [], $server, $content);
    }

    /**
     * @param  list<int>  $days  ISO weekdays
     */
    private function operator(Showroom $showroom, array $days = [1, 2, 3, 4, 5, 6, 7]): User
    {
        $operator = $this->employeeWith('site_operator', [P::ORDERS_VIEW, P::ORDERS_UPDATE], [$showroom], ['name' => 'Оператор']);
        $schedule = new DistributionSchedule;
        $schedule->forceFill(['user_id' => $operator->id, 'showroom_id' => $showroom->id, 'participates' => true]);

        foreach (DistributionSchedule::WEEKDAYS as $day => $column) {
            $schedule->setAttribute($column, in_array($day, $days, true));
        }

        $schedule->save();

        return $operator;
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<Showroom>  $showrooms
     */
    private function employeeWith(string $role, array $permissions, array $showrooms, array $attributes = []): User
    {
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web'], ['label' => $role])->syncPermissions($permissions);

        return $this->employee($role, $showrooms, $attributes);
    }

    private function failingDistribution(): void
    {
        $this->app->instance(CallbackDistributor::class, new class(app(AuditLogger::class), app(BusinessDayCalculator::class)) extends CallbackDistributor
        {
            public function attempt(int $orderId, ?User $actor = null): never
            {
                throw new RuntimeException('Distribution is down.');
            }
        });
        $this->app->forgetInstance(SiteRequestProcessor::class);
    }
}
