<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FlashMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shares_empty_flash_slots_when_nothing_was_flashed(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null)
            ->where('flash.error', null)
            ->where('flash.warning', null));
    }

    public function test_it_shares_flashed_messages_by_kind(): void
    {
        $this->withSession(['success' => 'Saved', 'error' => 'Failed', 'warning' => 'Careful'])
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.success', 'Saved')
                ->where('flash.error', 'Failed')
                ->where('flash.warning', 'Careful'));
    }

    public function test_it_shows_a_flashed_message_only_on_the_next_visit(): void
    {
        $this->from('/')->put('/settings/theme', ['preference' => 'dark']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('flash.success', __('settings.theme_saved')));
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('flash.success', null));
    }
}
