<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_the_landing_page_names_what_a_tunnel_is_for_and_how_to_start(): void
    {
        config(['exposehub.agent_server' => 'https://connect.tunnels.example', 'exposehub.tunnel_domain' => 'tunnels.example']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder([
                'Share a local site',
                'Receive webhooks',
                'Test on your phone',
                'Keep strangers out',
                'Use your own domain',
            ])
            ->assertSee('curl -fsSL https://connect.tunnels.example/install.sh | sh')
            ->assertSee('exposehub login')
            ->assertDontSee('login --server')
            ->assertSee('exposehub http 8000');
    }

    public function test_it_explains_what_is_seen_and_kept_of_each_request(): void
    {
        config(['exposehub.agent_server' => 'https://connect.tunnels.example', 'exposehub.log_retention_days' => 3]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSeeInOrder(['See what came through the tunnel', 'Live, on your machine', 'Afterwards, in the dashboard'])
            ->assertSee('A summary of each request stays for 3 days')
            ->assertSee('--log-bodies')
            ->assertSee('https://connect.tunnels.example/agent/exposehub-windows-amd64.exe', false);
    }

    public function test_links_to_it_get_a_preview_image_in_chat_apps(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<meta property="og:image" content="'.asset('og.png').'" />', false)
            ->assertSee('<meta property="og:title" content="exposehub" />', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image" />', false);
        $this->assertFileExists(public_path('og.png'));
    }

    public function test_every_scene_of_the_film_has_a_control_and_something_to_show(): void
    {
        $html = $this->get(route('home'))->getContent();

        foreach (range(0, 4) as $scene) {
            $this->assertStringContainsString('data-film-scene="'.$scene.'"', $html);
            $this->assertMatchesRegularExpression('/class="[^"]*\bscene s'.$scene.'\b/', $html, "Scene {$scene} has nothing on the stage.");
        }
    }
}
