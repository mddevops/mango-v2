<?php

namespace Database\Factories;

use App\Enums\DepartmentKind;
use App\Models\Department;
use App\Models\Showroom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'showroom_id' => Showroom::factory(),
            'code' => 'dep-'.fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Отдел '.fake()->unique()->word(),
            'kind' => DepartmentKind::Showroom->value,
        ];
    }

    public function shared(DepartmentKind $kind = DepartmentKind::CallCenter): static
    {
        return $this->state(fn () => [
            'showroom_id' => null,
            'kind' => $kind->value,
        ]);
    }
}
