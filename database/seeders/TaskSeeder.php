<?php

namespace Database\Seeders;

use App\Models\Objective;
use App\Models\Task;
use Illuminate\Database\Seeder;

class TaskSeeder extends Seeder
{
    /**
     * Give every objective that has no tasks yet 3-6 tasks.
     * Progress rolls up to objectives and strategies via model events.
     */
    public function run(): void
    {
        Objective::doesntHave('tasks')->each(function (Objective $objective) {
            Task::factory(random_int(3, 6))->for($objective)->create();
        });
    }
}
