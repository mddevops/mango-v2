<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\ClientPhone;
use App\Models\Order;
use App\Models\OrderPhone;
use App\Models\Role;
use App\Models\Showroom;
use App\Models\Source;
use App\Models\User;
use App\Services\OrderManager;
use App\Support\PermissionCatalog as P;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * The main phone lives in `orders.phone`, additional ones in `order_phones`.
 */
class OrderPhonesTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use RefreshDatabase;

    private const MAIN = '79515346783';

    private const EXTRA = '79000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
    }

    public function test_manual_order_keeps_the_main_number_only_in_orders_and_additional_ones_only_in_order_phones(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $admin = $this->administrator();

        $order = $this->createOrder($admin, $source, [['number' => '8 951 534-67-83', 'name' => null], ['number' => '+7 (900) 000-00-01', 'name' => 'Жена']]);

        $this->assertSame(self::MAIN, $order->phone);
        $this->assertSame([[self::EXTRA, 'Жена', 1]], OrderPhone::query()->where('order_id', $order->id)->get()->map(fn ($phone) => [$phone->phone, $phone->name, $phone->position])->all());
        $this->assertFalse(OrderPhone::query()->where('phone', self::MAIN)->exists());
        $this->assertEqualsCanonicalizing([self::MAIN, self::EXTRA], ClientPhone::query()->pluck('phone')->all());

        $this->actingAs($admin)->get(route('orders.show', $order->id))
            ->assertInertia(fn (Assert $page) => $page->where('order.phones', [
                ['value' => self::MAIN, 'formatted' => '+7 951 534-67-83', 'name' => null],
                ['value' => self::EXTRA, 'formatted' => '+7 900 000-00-01', 'name' => 'Жена'],
            ]));
    }

    public function test_duplicates_after_normalization_are_rejected_by_the_form_and_never_stored_by_the_service(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $admin = $this->administrator();

        $this->actingAs($admin)->post(route('orders.store'), $this->payload($source, [['number' => '89515346783', 'name' => null], ['number' => '+7 951 534-67-83', 'name' => null]]))
            ->assertSessionHasErrors('phones');

        $order = app(OrderManager::class)->create($admin, [
            'showroom_id' => $showroom->id,
            'source_id' => $source->id,
            'contact_channel_id' => $this->channelOf($source),
            'contact_name' => null,
            'phones' => [self::MAIN, self::MAIN, self::EXTRA, self::EXTRA],
            'phone_names' => [],
            'car_interest' => null,
        ]);

        $this->assertSame(self::MAIN, $order->phone);
        $this->assertSame([self::EXTRA], $order->additionalPhones()->pluck('phone')->all());
    }

    public function test_replacing_the_main_number_does_not_keep_the_old_one_unless_it_is_listed_and_is_audited(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $admin = $this->administrator();
        $order = $this->createOrder($admin, $source, [['number' => self::MAIN, 'name' => null], ['number' => self::EXTRA, 'name' => 'Жена']]);
        $stale = $order->lock_version;

        $this->actingAs($admin)->put(route('orders.update', $order->id), $this->updatePayload($order, [['number' => '+7 900 000-00-02', 'name' => null], ['number' => self::EXTRA, 'name' => 'Жена']]))
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('79000000002', $order->phone);
        $this->assertSame([self::EXTRA], $order->additionalPhones()->pluck('phone')->all());
        $this->assertSame($stale + 1, $order->lock_version);
        $this->assertTrue(ClientPhone::query()->where('phone', self::MAIN)->exists(), 'A number removed from one order stays with the client.');
        $this->assertSame(
            ['+7 951 534-67-83, +7 900 000-00-01 (Жена)', '+7 900 000-00-02, +7 900 000-00-01 (Жена)'],
            AuditLog::query()->where('event', 'order.updated')->sole()->changes['phones'],
        );

        $this->actingAs($admin)->put(route('orders.update', $order->id), $this->updatePayload($order, [['number' => self::EXTRA, 'name' => null], ['number' => self::MAIN, 'name' => null]], ['lock_version' => $stale]))
            ->assertSessionHasErrors('lock_version');
        $this->assertSame('79000000002', $order->fresh()->phone);

        $this->actingAs($admin)->put(route('orders.update', $order->id), $this->updatePayload($order, [['number' => self::EXTRA, 'name' => null], ['number' => '79000000002', 'name' => 'Старый основной']]))
            ->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(self::EXTRA, $order->phone);
        $this->assertSame([['79000000002', 'Старый основной']], $order->additionalPhones()->get()->map(fn ($phone) => [$phone->phone, $phone->name])->all());
    }

    public function test_changing_one_order_does_not_touch_the_contacts_of_other_orders(): void
    {
        $first = Showroom::factory()->create();
        $second = Showroom::factory()->create();
        $admin = $this->administrator();
        $one = $this->createOrder($admin, $this->makeSource($first), [['number' => self::MAIN, 'name' => null], ['number' => self::EXTRA, 'name' => 'Жена']]);
        $other = $this->createOrder($admin, $this->makeSource($second), [['number' => self::MAIN, 'name' => null], ['number' => self::EXTRA, 'name' => 'Жена']]);

        $this->actingAs($admin)->put(route('orders.update', $one->id), $this->updatePayload($one, [['number' => self::MAIN, 'name' => null]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $one->additionalPhones()->count());
        $this->assertSame([self::MAIN, self::EXTRA], array_column($other->fresh()->contactPhones(), 'phone'));
        $this->assertSame($one->client_id, $other->client_id);
        $this->assertTrue(ClientPhone::query()->where('phone', self::EXTRA)->exists());
    }

    public function test_additional_number_identifies_the_client_for_a_repeat(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);
        $admin = $this->administrator();

        $first = $this->createOrder($admin, $source, [['number' => self::MAIN, 'name' => null], ['number' => self::EXTRA, 'name' => null]]);
        $repeat = $this->createOrder($admin, $source, [['number' => '+7 900 000-00-01', 'name' => null]]);

        $this->assertSame($first->client_id, $repeat->client_id);
        $this->assertSame([0, 1], [$first->repeat_number, $repeat->repeat_number]);
        $this->assertSame(self::EXTRA, $repeat->phone);
    }

    public function test_search_finds_main_and_additional_numbers_only_within_the_scope(): void
    {
        $own = Showroom::factory()->create();
        $foreign = Showroom::factory()->create();
        $admin = $this->administrator();
        $theirs = $this->createOrder($admin, $this->makeSource($foreign), [['number' => '79110000000', 'name' => null], ['number' => self::EXTRA, 'name' => null]]);
        $mine = $this->createOrder($admin, $this->makeSource($own), [['number' => self::MAIN, 'name' => null], ['number' => self::EXTRA, 'name' => null]]);
        $employee = $this->employeeWith('phone_worker', [P::ORDERS_VIEW, P::ORDERS_VIEW_ALL], [$own]);

        $found = fn (User $user, string $search) => $this->actingAs($user)->get(route('orders.index', ['search' => $search]));

        $found($employee, '+7 951 534-67-83')->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 1)->where('orders.data.0.id', $mine->id)->where('orders.data.0.phone', '+7 951 534-67-83'));
        $found($employee, '8 900 000-00-01')->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 1)->where('orders.data.0.id', $mine->id));
        $found($employee, '+7 911 000-00-00')->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 0));
        $found($employee, '0001')->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 0));

        $found($admin, '8 900 000-00-01')->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 2)->has('orders.data', 2));
        $found($admin, '+7 911 000-00-00')->assertInertia(fn (Assert $page) => $page->where('orders.meta.total', 1)->where('orders.data.0.id', $theirs->id));
    }

    public function test_the_list_reads_the_main_number_without_loading_phone_tables(): void
    {
        $showroom = Showroom::factory()->create();
        $admin = $this->administrator();
        $this->createOrder($admin, $this->makeSource($showroom), [['number' => self::MAIN, 'name' => null], ['number' => self::EXTRA, 'name' => null]]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.phone', '+7 951 534-67-83'));
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('order_phones', $queries);
        $this->assertStringNotContainsString('client_phones', $queries);
    }

    public function test_the_service_requires_a_main_number(): void
    {
        $showroom = Showroom::factory()->create();
        $source = $this->makeSource($showroom);

        $this->expectException(ValidationException::class);

        app(OrderManager::class)->create($this->administrator(), [
            'showroom_id' => $showroom->id,
            'source_id' => $source->id,
            'contact_channel_id' => $this->channelOf($source),
            'contact_name' => null,
            'phones' => ['6783'],
            'phone_names' => [],
            'car_interest' => null,
        ]);
    }

    /**
     * @param  list<array{number: string, name: string|null}>  $phones
     */
    private function createOrder(User $actor, Source $source, array $phones): Order
    {
        $this->actingAs($actor)->post(route('orders.store'), $this->payload($source, $phones))->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  list<array{number: string, name: string|null}>  $phones
     * @return array<string, mixed>
     */
    private function payload(Source $source, array $phones): array
    {
        return [
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => $this->channelOf($source),
            'contact_name' => 'Клиент',
            'phones' => $phones,
            'car_interest' => null,
        ];
    }

    /**
     * @param  list<array{number: string, name: string|null}>  $phones
     * @return array<string, mixed>
     */
    private function updatePayload(Order $order, array $phones, array $overrides = []): array
    {
        $order->refresh();

        return [
            'contact_channel_id' => $order->contact_channel_id,
            'contact_name' => $order->contact_name,
            'phones' => $phones,
            'car_interest' => $order->car_interest,
            'order_status_id' => $order->order_status_id,
            'callback_at' => null,
            'arrival_at' => null,
            'reason_id' => null,
            'reason_comment' => null,
            'lock_version' => $order->lock_version,
            ...$overrides,
        ];
    }

    private function channelOf(Source $source): int
    {
        return (int) $source->channels()->orderBy('contact_channels.id')->value('contact_channels.id');
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<Showroom>  $showrooms
     */
    private function employeeWith(string $role, array $permissions, array $showrooms): User
    {
        Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web'], ['label' => $role])->syncPermissions($permissions);

        return $this->employee($role, $showrooms);
    }
}
