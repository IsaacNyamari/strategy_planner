<?php

use App\EisenhowerQuadrant;
use App\Models\Objective;
use App\Models\Strategy;
use App\Models\Task;
use App\TasksEnum;
use Livewire\Livewire;

test('guests are redirected from planner pages', function (string $route) {
    $strategy = Strategy::factory()->create();

    $this->get(route($route, $route === 'strategies.show' ? $strategy : []))
        ->assertRedirect(route('login'));
})->with(['strategies', 'strategies.show', 'tasks']);

test('authenticated users can view planner pages', function () {
    $this->actingAs(chairman());
    $task = Task::factory()->create();
    $strategy = $task->objective->strategy;

    $this->get(route('dashboard'))->assertOk()->assertSee('Eisenhower matrix');
    $this->get(route('strategies'))->assertOk()->assertSee($strategy->title);
    $this->get(route('strategies.show', $strategy))->assertOk()->assertSee($task->title);
    $this->get(route('tasks'))->assertOk()->assertSee($task->title);
    $this->get(route('tasks', ['view' => 'matrix']))->assertOk();
});

test('progress rolls up from tasks to objective to strategy', function () {
    $objective = Objective::factory()->create();
    Task::factory()->for($objective)->completed()->create(['effort_hours' => 3]);
    $open = Task::factory()->for($objective)->notStarted()->create(['effort_hours' => 1]);

    expect($objective->fresh()->current_progress)->toBe(75)
        ->and($objective->fresh()->status)->toBe(TasksEnum::IN_PROGRESS)
        ->and($objective->strategy->fresh()->current_progress)->toBe(75);

    $open->update(['status' => TasksEnum::COMPLETED]);

    expect($open->fresh()->completed_at)->not->toBeNull()
        ->and($objective->fresh()->current_progress)->toBe(100)
        ->and($objective->fresh()->status)->toBe(TasksEnum::COMPLETED);

    $open->delete();
    expect($objective->fresh()->current_progress)->toBe(100);
});

test('getColor maps progress to colours', function () {
    expect(getColor(0))->toBe('red')
        ->and(getColor(24))->toBe('red')
        ->and(getColor(25))->toBe('orange')
        ->and(getColor(50))->toBe('yellow')
        ->and(getColor(100))->toBe('green');
});

test('a strategy can be created', function () {
    $this->actingAs(chairman());

    Livewire::test('pages::strategies.index')
        ->set('title', 'Grow revenue')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Strategy::where('title', 'Grow revenue')->exists())->toBeTrue();
});

test('strategy title is required', function () {
    $this->actingAs(chairman());

    Livewire::test('pages::strategies.index')
        ->call('create')
        ->assertHasErrors(['title' => 'required']);
});

test('objectives and tasks can be added from the strategy page', function () {
    $this->actingAs(chairman());
    $strategy = Strategy::factory()->create();

    $component = Livewire::test('pages::strategies.show', ['strategy' => $strategy])
        ->call('newObjective')
        ->set('objectiveTitle', 'Launch beta')
        ->call('saveObjective')
        ->assertHasNoErrors();

    $objective = $strategy->objectives()->firstOrFail();

    $component->call('newTask', $objective->id)
        ->set('taskTitle', 'Write onboarding docs')
        ->set('taskQuadrant', EisenhowerQuadrant::DO_FIRST->value)
        ->call('saveTask')
        ->assertHasNoErrors()
        ->assertSee('Write onboarding docs');

    $task = $objective->tasks()->firstOrFail();
    expect($task->eisenhower_quadrant)->toBe(EisenhowerQuadrant::DO_FIRST)
        ->and($task->status)->toBe(TasksEnum::NOT_STARTED);

    $component->call('setTaskStatus', $task->id, TasksEnum::COMPLETED->value);
    expect($strategy->fresh()->current_progress)->toBe(100);
});

test('tasks can be created, filtered and updated on the tasks page', function () {
    $this->actingAs(chairman());
    $objective = Objective::factory()->create();
    $other = Task::factory()->notStarted()->create(['title' => 'Unrelated chore', 'eisenhower_quadrant' => EisenhowerQuadrant::ELIMINATE]);

    $component = Livewire::test('pages::task.index')
        ->set('objective_id', $objective->id)
        ->set('title', 'Ship pricing page')
        ->set('eisenhower_quadrant', EisenhowerQuadrant::DO_FIRST->value)
        ->call('create')
        ->assertHasNoErrors();

    $task = Task::where('title', 'Ship pricing page')->firstOrFail();

    $component->set('quadrant', EisenhowerQuadrant::DO_FIRST->value)
        ->assertSee('Ship pricing page')
        ->assertDontSee('Unrelated chore');

    $component->call('setStatus', $task->id, TasksEnum::BLOCKED->value);
    expect($task->fresh()->status)->toBe(TasksEnum::BLOCKED);

    $component->call('setQuadrant', $other->id, EisenhowerQuadrant::SCHEDULE->value);
    expect($other->fresh()->eisenhower_quadrant)->toBe(EisenhowerQuadrant::SCHEDULE);
});
