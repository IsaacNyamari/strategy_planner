<?php

use Laravel\Fortify\Features;

test('the login page offers sign up while registration is open', function () {
    $this->get(route('login'))->assertOk()->assertSee('Sign up');
});

test('the login page still works and hides sign up when registration is closed', function () {
    config(['fortify.features' => array_values(array_filter(
        config('fortify.features'),
        fn ($feature) => $feature !== Features::registration(),
    ))]);

    expect(Features::enabled(Features::registration()))->toBeFalse();

    $this->get(route('login'))->assertOk()->assertDontSee('Sign up');
});
