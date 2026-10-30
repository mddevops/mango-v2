<?php

namespace Database\Factories;

use App\Models\Showroom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Showroom>
 */
class ShowroomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'sr-'.fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Салон '.fake()->unique()->city(),
            'address' => fake()->address(),
            'phone' => '+7 900 '.fake()->numerify('###-##-##'),
            'timezone' => 'Europe/Moscow',
        ];
    }

    public function inactive(): static
    {
        return $this->afterCreating(function (Showroom $showroom) {
            $showroom->forceFill(['active' => false])->save();
        });
    }
}
