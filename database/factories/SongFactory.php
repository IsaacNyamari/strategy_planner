<?php

namespace Database\Factories;

use App\Enums\SongCategory;
use App\Enums\SongLanguage;
use App\Models\Song;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Song>
 */
class SongFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => rtrim($this->faker->sentence(3), '.'),
            'language' => $this->faker->randomElement(SongLanguage::cases()),
            'category' => $this->faker->randomElement(SongCategory::cases()),
            'artist' => $this->faker->name(),
            'musical_key' => $this->faker->randomElement(['C', 'D', 'E', 'F', 'G', 'A', 'Bb']),
            'tempo_bpm' => $this->faker->numberBetween(60, 140),
            'time_signature' => '4/4',
            'source' => 'manual',
        ];
    }
}
