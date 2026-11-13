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

    /**
     * Chat apps show the site's icon beside the preview, and they read the
     * .ico and the touch icon, not the SVG. Both shipped as the framework's
     * own logo once.
     */
    public function test_the_icons_chat_apps_and_phones_read_are_the_products_own(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', false)
            ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);

        // An .ico with the three sizes browsers and chat apps ask for.
        $ico = (string) file_get_contents(public_path('favicon.ico'));
        $this->assertSame(['reserved' => 0, 'type' => 1, 'count' => 3], unpack('vreserved/vtype/vcount', $ico));
        $this->assertSame([16, 32, 48], array_map(fn (int $entry) => ord($ico[6 + 16 * $entry]), [0, 1, 2]));

        // The touch icon at the size iOS asks for, not the framework's file.
        $this->assertSame([180, 180], array_slice((array) getimagesize(public_path('apple-touch-icon.png')), 0, 2));
        $frameworks = [
            'favicon.ico' => '4606a56e6ef3f5ec39201497f57069d5457ce9cea25227134d0ba378788e9070',
            'apple-touch-icon.png' => '4001aa032ff113e1a268a9bbf1ab0fd9949439f9f54a85895956eb323aba977d',
        ];
        foreach ($frameworks as $file => $hash) {
            $this->assertNotSame($hash, hash_file('sha256', public_path($file)), $file.' is the framework\'s default icon.');
        }
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
