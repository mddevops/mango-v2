<?php

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Showroom;
use App\Models\User;
use Database\Seeders\OrderReferenceDataSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\BuildsOrganization;
use Tests\Feature\Sources\Concerns\BuildsSources;
use Tests\TestCase;

/**
 * Runs the main phone migration back and forth on the test database: DDL commits
 * implicitly, so truncation replaces the wrapping transaction. `down()` produces the
 * former layout (main number at position 0) from real orders.
 */
class OrderPhoneMigrationTest extends TestCase
{
    use BuildsOrganization;
    use BuildsSources;
    use DatabaseTruncation;

    private Migration $migration;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(OrderReferenceDataSeeder::class);
        $this->admin = $this->administrator();
        $this->migration = require database_path('migrations/2026_10_24_120000_move_main_order_phone_to_orders.php');
    }

    protected function tearDown(): void
    {
        if (! Schema::hasColumn('orders', 'phone')) {
            DB::table('order_phones')->delete();
            $this->migration->up();
        }

        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_main_numbers_move_to_orders_and_additional_numbers_stay(): void
    {
        $source = $this->makeSource(Showroom::factory()->create());
        $single = $this->order($source, ['79515346783']);
        $several = $this->order($source, ['79000000001', '79000000002', '79000000003'], [1 => 'Жена']);
        $updatedAt = DB::table('orders')->where('id', $several->id)->value('updated_at');
        $audits = DB::table('audit_logs')->count();

        $this->migration->down();

        $this->assertFalse(Schema::hasColumn('orders', 'phone'));
        $this->assertSame(
            [[$single->id, '79515346783', 0], [$several->id, '79000000001', 0], [$several->id, '79000000002', 1], [$several->id, '79000000003', 2]],
            DB::table('order_phones')->orderBy('order_id')->orderBy('position')->get()->map(fn ($row) => [$row->order_id, $row->phone, $row->position])->all(),
        );

        $this->migration->up();

        $this->assertSame(['79515346783', '79000000001'], DB::table('orders')->orderBy('id')->pluck('phone')->all());
        $this->assertSame(
            [[$several->id, '79000000002', 'Жена', 1], [$several->id, '79000000003', null, 2]],
            DB::table('order_phones')->orderBy('position')->get()->map(fn ($row) => [$row->order_id, $row->phone, $row->name, $row->position])->all(),
        );
        $this->assertFalse(DB::table('order_phones')->where('position', 0)->exists());
        $this->assertTrue(Schema::hasIndex('orders', 'orders_phone_index'));
        $this->assertFalse(collect(Schema::getColumns('orders'))->firstWhere('name', 'phone')['nullable']);
        $this->assertSame($updatedAt, DB::table('orders')->where('id', $several->id)->value('updated_at'));
        $this->assertSame($audits, DB::table('audit_logs')->count(), 'The technical move writes no order events.');
    }

    public function test_ambiguous_source_data_stops_the_migration_without_changes_and_it_resumes_after_repair(): void
    {
        $source = $this->makeSource(Showroom::factory()->create());
        $missing = $this->order($source, ['79515346783', '79000000001']);
        $double = $this->order($source, ['79000000002', '79000000003']);
        $broken = $this->order($source, ['79000000004']);
        $fine = $this->order($source, ['79000000005']);

        $this->migration->down();
        DB::table('order_phones')->where('order_id', $missing->id)->where('position', 0)->delete();
        DB::table('order_phones')->where('order_id', $double->id)->where('position', 1)->update(['position' => 0]);
        DB::table('order_phones')->where('order_id', $broken->id)->update(['phone' => '8 900 000-00-04']);
        $before = DB::table('order_phones')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        try {
            $this->migration->up();
            $this->fail('The migration must stop on ambiguous data.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        $this->assertStringContainsString("нет основного номера: заявки {$missing->id}", $message);
        $this->assertStringContainsString("несколько основных номеров: заявки {$double->id}", $message);
        $this->assertStringContainsString("номер не нормализуется: заявки {$broken->id}", $message);
        $this->assertDoesNotMatchRegularExpression('/\b'.$fine->id.'\b/', $message);
        $this->assertDoesNotMatchRegularExpression('/\d{3}\D?\d{3}\D?\d{2}\D?\d{2}/', $message, 'No phone number in the report.');
        $this->assertSame($before, DB::table('order_phones')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(4, DB::table('orders')->whereNull('phone')->count());

        DB::table('order_phones')->where('order_id', $missing->id)->update(['position' => 0]);
        DB::table('order_phones')->where('order_id', $double->id)->where('phone', '79000000003')->update(['position' => 1]);
        DB::table('order_phones')->where('order_id', $broken->id)->update(['phone' => '79000000004']);

        $this->migration->up();

        $this->assertSame(['79000000001', '79000000002', '79000000004', '79000000005'], DB::table('orders')->orderBy('id')->pluck('phone')->all());
        $this->assertSame([[$double->id, '79000000003']], DB::table('order_phones')->get()->map(fn ($row) => [$row->order_id, $row->phone])->all());
    }

    /**
     * @param  list<string>  $phones
     * @param  array<int, string>  $names  by position
     */
    private function order($source, array $phones, array $names = []): Order
    {
        $rows = array_map(fn (string $phone, int $index) => ['number' => $phone, 'name' => $names[$index] ?? null], $phones, array_keys($phones));

        $this->actingAs($this->admin)->post(route('orders.store'), [
            'showroom_id' => $source->showroom_id,
            'source_id' => $source->id,
            'contact_channel_id' => $source->channels()->orderBy('contact_channels.id')->value('contact_channels.id'),
            'contact_name' => 'Клиент',
            'phones' => $rows,
            'car_interest' => null,
        ])->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }
}
