<?php

namespace Database\Seeders;

use App\Models\Objective;
use App\Models\Strategy;
use Illuminate\Database\Seeder;

class ObjectiveSeeder extends Seeder
{
    /**
     * Give every strategy that has no objectives yet 2-4 objectives.
     */
    public function run(): void
    {
        Strategy::doesntHave('objectives')->each(function (Strategy $strategy) {
            Objective::factory(random_int(2, 4))->for($strategy)->create();
        });
    }
}
