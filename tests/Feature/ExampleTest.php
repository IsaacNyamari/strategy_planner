<?php

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect('/login'); // the home page sends people to log in
});
