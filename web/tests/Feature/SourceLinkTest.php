<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_link_the_source_of_the_running_version(): void
    {
        config(['exposehub.source_url' => 'https://git.example.com/fork', 'exposehub.version' => '0.1.0']);

        foreach ([route('home'), route('docs'), route('login')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('href="https://git.example.com/fork"', false)
                ->assertSee('v0.1.0');
        }
    }

    public function test_the_signed_in_layout_links_the_source(): void
    {
        config(['exposehub.source_url' => 'https://git.example.com/fork', 'exposehub.version' => 'dev']);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="https://git.example.com/fork"', false)
            ->assertSee('Source');
    }

    public function test_the_link_can_be_turned_off(): void
    {
        config(['exposehub.source_url' => '']);

        $this->get(route('home'))->assertOk()->assertDontSee('>Source<', false);
    }

    public function test_the_health_check_answers_without_a_session(): void
    {
        $this->get('/up')->assertOk()->assertCookieMissing(config('session.cookie'));
    }
}
