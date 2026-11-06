<?php

namespace Database\Factories;

use App\EisenhowerQuadrant;
use App\Models\Objective;
use App\Models\Task;
use App\TasksEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'objective_id' => Objective::factory(),
            'title' => rtrim($this->faker->sentence(5), '.'),
            'description' => $this->faker->sentence(14),
            'status' => $this->faker->randomElement(TasksEnum::cases()),
            'eisenhower_quadrant' => $this->faker->randomElement(EisenhowerQuadrant::cases()),
            'effort_hours' => $this->faker->numberBetween(1, 16),
            'due_at' => $this->faker->dateTimeBetween('-2 weeks', '+2 months'),
        ];
    }

    public function completed(): static
    {
        return $this->state(['status' => TasksEnum::COMPLETED]);
    }

    public function notStarted(): static
    {
        return $this->state(['status' => TasksEnum::NOT_STARTED]);
    }
}
