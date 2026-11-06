<?php

namespace Database\Factories;

use App\Models\Objective;
use App\Models\Strategy;
use App\TasksEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Objective>
 */
class ObjectiveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'strategy_id' => Strategy::factory(),
            'title' => rtrim($this->faker->sentence(4), '.'),
            'description' => $this->faker->sentence(12),
            'current_progress' => 0,
            'metric_unit' => '%',
            'status' => TasksEnum::NOT_STARTED,
            'target_date' => $this->faker->dateTimeBetween('+1 month', '+9 months'),
        ];
    }
}
