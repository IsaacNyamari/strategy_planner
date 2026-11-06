<?php

namespace Database\Factories;

use App\Enums\ServiceType;
use App\Models\Ministration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ministration>
 */
class MinistrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => $this->faker->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'starts_at' => '09:00',
            'ends_at' => '12:00',
            'type' => ServiceType::SUNDAY,
        ];
    }

    public function special(string $title = 'Special meeting'): static
    {
        return $this->state(['type' => ServiceType::SPECIAL, 'title' => $title]);
    }
}
