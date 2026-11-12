<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocsPageTest extends TestCase
{
    public function test_the_docs_are_public_and_use_this_installations_values(): void
    {
        config(['exposehub.agent_server' => 'https://connect.tunnels.example', 'exposehub.tunnel_domain' => 'tunnels.example', 'exposehub.log_retention_days' => 5]);

        $this->get(route('docs'))
            ->assertOk()
            ->assertSee('curl -fsSL https://connect.tunnels.example/install.sh | sh')
            ->assertSee('https://shop.tunnels.example')
            ->assertSee('for 5 days')
            ->assertSeeInOrder(['Get started', 'Share with colleagues', 'Webhooks', 'Inspector', 'Request logs', 'Password protection', 'Your own domain', 'Commands and flags'])
            ->assertSee('<meta property="og:title" content="Docs - exposehub" />', false);
    }

    public function test_dashboard_links_send_guests_to_sign_in(): void
    {
        $this->get(route('docs'))->assertSee('href="'.route('login').'"', false);
    }

    public function test_every_page_footer_links_to_the_docs(): void
    {
        $this->get(route('home'))->assertSee('href="'.route('docs').'"', false);
    }
}
