<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        )->forceFill(['email_verified_at' => now()])->save();

        $this->call([
            StrategySeeder::class,
            ObjectiveSeeder::class,
            TaskSeeder::class,
            WorshipTeamSeeder::class,
        ]);
    }
}
