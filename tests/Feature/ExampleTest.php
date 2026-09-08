<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_root_is_an_intentional_blank_placeholder(): void
    {
        // Root reveals neither surface: operators use /admin, customers /user.
        // It renders a neutral placeholder (200), never a redirect to either.
        $this->get('/')->assertOk()->assertDontSee('/admin')->assertDontSee('/user');
    }
}
