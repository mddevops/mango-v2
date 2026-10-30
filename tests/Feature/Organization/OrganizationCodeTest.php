<?php

namespace Tests\Feature\Organization;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Showroom;
use App\Support\CodeGenerator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class OrganizationCodeTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function createShowroom(string $name): Showroom
    {
        $this->actingAs($this->administrator())
            ->post(route('showrooms.store'), ['name' => $name, 'timezone' => 'Europe/Moscow'])
            ->assertSessionHasNoErrors();

        return Showroom::query()->latest('id')->firstOrFail();
    }

    private function createDepartment(?Showroom $showroom, string $name): Department
    {
        $this->actingAs($this->administrator())
            ->post(route('departments.store'), [
                'showroom_id' => $showroom?->id,
                'name' => $name,
                'kind' => $showroom ? 'showroom' : 'other',
            ])
            ->assertSessionHasNoErrors();

        return Department::query()->latest('id')->firstOrFail();
    }

    public function test_base_code_is_transliterated_lowercase_slug(): void
    {
        $this->assertSame('moskva-sever', CodeGenerator::base('  Москва   Север ', 'showroom'));
        $this->assertSame('koll-tsentr-1', CodeGenerator::base('Колл-центр №1', 'department'));
        $this->assertSame('yolki-palki-schuka', CodeGenerator::base('Ёлки-Палки щука', 'department'));
        $this->assertSame('salon-test-2', CodeGenerator::base('Салон_Тест 2', 'showroom'));
        $this->assertSame('showroom', CodeGenerator::base('!!! ###', 'showroom'));
        $this->assertSame('department', CodeGenerator::base('日本', 'department'));
    }

    public function test_suffix_keeps_code_within_column_length(): void
    {
        $base = CodeGenerator::base(str_repeat('Очень длинное название ', 10), 'showroom');

        $this->assertSame(CodeGenerator::MAX_LENGTH, strlen($base));
        $this->assertSame(CodeGenerator::MAX_LENGTH, strlen(CodeGenerator::candidate($base, 2)));
        $this->assertStringEndsWith('-2', CodeGenerator::candidate($base, 2));
        $this->assertStringEndsWith('-123', CodeGenerator::candidate($base, 123));
        $this->assertLessThanOrEqual(CodeGenerator::MAX_LENGTH, strlen(CodeGenerator::candidate($base, 123)));
        $this->assertStringStartsWith(CodeGenerator::prefix($base), CodeGenerator::candidate($base, 9_999_999));
        $this->assertSame('a-3', CodeGenerator::firstFree('a', collect(['a', 'a-2', 'a-4'])));
    }

    public function test_showroom_is_created_without_code_and_gets_unique_codes(): void
    {
        Showroom::factory()->inactive()->create(['code' => 'moskva', 'name' => 'Старая Москва']);

        $this->assertSame('moskva-2', $this->createShowroom('Москва')->code, 'Inactive showrooms keep their codes reserved.');
        $this->assertSame('moskva-3', $this->createShowroom('Москва')->code);
        $this->assertSame('moskva-sever', $this->createShowroom('Москва Север')->code);
        $this->assertSame('showroom', $this->createShowroom('***')->code);
        $this->assertSame('showroom-2', $this->createShowroom('№ !')->code);

        $long = str_repeat('Очень длинное название ', 10);
        $first = $this->createShowroom($long);
        $second = $this->createShowroom($long);
        $this->assertSame(CodeGenerator::MAX_LENGTH, strlen($first->code));
        $this->assertSame(CodeGenerator::MAX_LENGTH, strlen($second->code));
        $this->assertStringEndsWith('-2', $second->code);

        $this->assertDatabaseHas('audit_logs', ['event' => 'showroom.created', 'subject_id' => $first->id]);
        $this->assertSame($first->code, AuditLog::query()->where('event', 'showroom.created')->where('subject_id', $first->id)->value('changes')['code']);
    }

    public function test_department_codes_are_unique_per_showroom_and_among_shared(): void
    {
        [$first, $second] = Showroom::factory()->count(2)->create();
        Department::factory()->for($first)->create(['code' => 'prodazhi', 'active' => false]);

        $this->assertSame('prodazhi-2', $this->createDepartment($first, 'Продажи')->code);
        $this->assertSame('prodazhi', $this->createDepartment($second, 'Продажи')->code);
        $this->assertSame('prodazhi', $this->createDepartment(null, 'Продажи')->code);
        $this->assertSame('prodazhi-2', $this->createDepartment(null, 'Продажи')->code);
        $this->assertSame('department', $this->createDepartment(null, '—')->code);
        $this->assertSame('department', $this->createDepartment($first, '?')->code);
    }

    public function test_code_cannot_be_supplied_on_create(): void
    {
        $admin = $this->administrator();
        $showroom = Showroom::factory()->create();

        $this->actingAs($admin)
            ->post(route('showrooms.store'), ['code' => 'custom', 'name' => 'Свой код', 'timezone' => 'Europe/Moscow'])
            ->assertSessionHasErrors('code');
        $this->actingAs($admin)
            ->post(route('departments.store'), ['showroom_id' => $showroom->id, 'code' => 'custom', 'name' => 'Свой код', 'kind' => 'showroom'])
            ->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('showrooms', ['name' => 'Свой код']);
        $this->assertDatabaseMissing('departments', ['name' => 'Свой код']);
    }

    public function test_rename_keeps_code_and_code_cannot_be_changed_by_tampered_request(): void
    {
        $admin = $this->administrator();
        $showroom = $this->createShowroom('Москва');
        $department = $this->createDepartment($showroom, 'Продажи');

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), [
                'code' => 'hacked', 'name' => 'Казань', 'timezone' => 'Europe/Moscow', 'lock_version' => $showroom->lock_version,
            ])
            ->assertSessionHasErrors('code');
        $this->actingAs($admin)
            ->put(route('departments.update', $department), ['code' => 'hacked', 'name' => 'Сервис', 'lock_version' => $department->lock_version])
            ->assertSessionHasErrors('code');

        $this->assertSame(['moskva', 'Москва'], [$showroom->fresh()->code, $showroom->fresh()->name]);
        $this->assertSame(['prodazhi', 'Продажи'], [$department->fresh()->code, $department->fresh()->name]);

        $this->actingAs($admin)
            ->put(route('showrooms.update', $showroom), ['name' => 'Казань', 'timezone' => 'Europe/Moscow', 'lock_version' => $showroom->lock_version])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->put(route('departments.update', $department), ['name' => 'Сервис', 'lock_version' => $department->lock_version])
            ->assertSessionHasNoErrors();

        $this->assertSame(['moskva', 'Казань'], [$showroom->fresh()->code, $showroom->fresh()->name]);
        $this->assertSame(['prodazhi', 'Сервис'], [$department->fresh()->code, $department->fresh()->name]);
        $this->assertArrayNotHasKey('code', AuditLog::query()->where('event', 'showroom.updated')->value('changes'));
    }

    public function test_department_cannot_be_moved_to_another_showroom(): void
    {
        [$first, $second] = Showroom::factory()->count(2)->create();
        $department = $this->createDepartment($first, 'Продажи');

        $this->actingAs($this->administrator())
            ->put(route('departments.update', $department), [
                'showroom_id' => $second->id, 'name' => 'Продажи', 'lock_version' => $department->lock_version,
            ])
            ->assertSessionHasErrors(['showroom_id' => 'Перенос отдела в другой салон не поддерживается.']);

        $this->assertSame([$first->id, 'prodazhi'], [$department->fresh()->showroom_id, $department->fresh()->code]);
    }

    public function test_existing_codes_are_preserved(): void
    {
        $legacyShowroom = Showroom::factory()->create(['code' => 'msk_old-01', 'name' => 'Москва']);
        $legacyDepartment = Department::factory()->for($legacyShowroom)->create(['code' => 'Sales_Legacy', 'name' => 'Продажи']);
        $callCenter = Department::query()->where('code', 'call_center')->firstOrFail();

        $this->createShowroom('Москва');
        $this->createDepartment($legacyShowroom, 'Продажи');
        $this->createDepartment(null, 'Колл-центр');

        $this->actingAs($this->administrator())
            ->put(route('showrooms.update', $legacyShowroom), ['name' => 'Москва Юг', 'timezone' => 'Europe/Moscow', 'lock_version' => $legacyShowroom->lock_version])
            ->assertSessionHasNoErrors();

        $this->assertSame('msk_old-01', $legacyShowroom->fresh()->code);
        $this->assertSame('Sales_Legacy', $legacyDepartment->fresh()->code);
        $this->assertSame('call_center', $callCenter->fresh()->code);
        $this->assertSame(1, Department::query()->whereNull('showroom_id')->where('code', 'call_center')->count());
    }
}
