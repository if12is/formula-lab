<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the Home Inertia page at /', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Home'));
});
