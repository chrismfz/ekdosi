<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_root_redirects_to_the_admin_panel(): void
    {
        // There is no public landing page; root redirects into the panel.
        $this->get('/')->assertRedirect('/admin');
    }
}
