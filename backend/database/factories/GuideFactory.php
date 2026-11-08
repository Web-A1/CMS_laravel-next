<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class GuideFactory extends Factory
{
    public function definition(): array
    {
        return [
            'legacy_file_id' => fake()->unique()->numberBetween(1, 1000000),
            'slug' => fake()->unique()->slug(),
            'title' => fake()->sentence(3),
            'is_active' => false,
        ];
    }
}
