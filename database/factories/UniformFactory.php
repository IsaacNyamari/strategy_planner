<?php

namespace Database\Factories;

use App\Models\Uniform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Uniform>
 */
class UniformFactory extends Factory
{
    public function definition(): array
    {
        $color = $this->faker->randomElement(['White', 'Navy', 'Maroon', 'Gold', 'Black', 'Royal blue']);

        return [
            'name' => $color.' set',
            'top' => $color.' shirt / blouse',
            'ladies_bottom' => 'Black A-line dress',
            'men_bottom' => 'Black trousers',
            'colors' => $color.', black',
            'source' => 'manual',
            'is_active' => true,
        ];
    }
}
