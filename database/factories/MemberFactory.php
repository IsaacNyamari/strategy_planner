<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    public function definition(): array
    {
        $gender = $this->faker->randomElement(Gender::cases());

        return [
            'name' => $this->faker->name($gender->value),
            'gender' => $gender,
            'phone' => $this->faker->unique()->numerify('+2547########'),
            'email' => $this->faker->unique()->safeEmail(),
            'part' => $this->faker->randomElement(['Soprano', 'Alto', 'Tenor', 'Bass', 'Keys', 'Guitar', 'Drums', 'Bass guitar']),
            'can_lead_praise' => $this->faker->boolean(35),
            'can_lead_worship' => $this->faker->boolean(35),
            'status' => MemberStatus::ACTIVE,
            'joined_at' => now()->subYear(),
        ];
    }

    public function leader(): static
    {
        return $this->state(['can_lead_praise' => true, 'can_lead_worship' => true]);
    }

    public function praiseLeader(): static
    {
        return $this->state(['can_lead_praise' => true, 'can_lead_worship' => false]);
    }

    public function worshipLeader(): static
    {
        return $this->state(['can_lead_praise' => false, 'can_lead_worship' => true]);
    }

    public function backupOnly(): static
    {
        return $this->state(['can_lead_praise' => false, 'can_lead_worship' => false]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => MemberStatus::INACTIVE]);
    }

    /** New member on probation (end date defaults to the team's probation length). */
    public function probation(): static
    {
        return $this->state(['status' => MemberStatus::PROBATION, 'joined_at' => now()]);
    }
}
