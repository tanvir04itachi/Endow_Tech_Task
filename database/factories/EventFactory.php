<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'capacity' => 100,
            'reserved_count' => 0,
        ];
    }

    public function capacity(int $capacity): static
    {
        return $this->state(fn (array $attributes) => [
            'capacity' => $capacity,
        ]);
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'reserved_count' => $attributes['capacity'] ?? 100,
        ]);
    }
}
