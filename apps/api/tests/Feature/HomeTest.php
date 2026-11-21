<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the Dashboard Inertia page at /', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
});
