<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_read_this_installs_own_instructions_as_markdown(): void
    {
        config(['exposehub.agent_server' => 'https://connect.tunnels.example', 'exposehub.tunnel_domain' => 'tunnels.example']);

        $response = $this->get('/agents.md')->assertOk();

        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        $this->assertStringStartsWith('# exposehub at '.rtrim((string) config('app.url'), '/').':', $body);
        $this->assertStringContainsString('curl -fsSL https://connect.tunnels.example/install.sh | sh', $body);
        $this->assertStringContainsString('https://connect.tunnels.example/agent/exposehub-windows-amd64.exe', $body);
        $this->assertStringContainsString('"url":"https://k3x9q2.tunnels.example"', $body);
        $this->assertStringContainsString('claude mcp add exposehub -- exposehub mcp', $body);
        // The rules an agent has to follow come first.
        $this->assertLessThan(strpos($body, '## 1. Install'), strpos($body, 'Never ask the person for their agent token'));
        // Markdown, not HTML: nothing is escaped.
        $this->assertStringNotContainsString('&quot;', $body);
        $this->assertStringNotContainsString('&amp;', $body);
        $this->assertStringNotContainsString('<html', $body);
    }

    public function test_the_guide_is_never_cached_for_others_and_ignores_a_forged_host(): void
    {
        config(['app.url' => 'https://dash.tunnels.example']);

        $response = $this->get('http://evil.example/agents.md')->assertOk();

        // It comes with session cookies, which a shared cache must not hand on.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));
        $body = (string) $response->getContent();
        $this->assertStringStartsWith('# exposehub at https://dash.tunnels.example:', $body);
        $this->assertStringNotContainsString('evil.example', $body);
    }

    public function test_the_overview_and_the_docs_hand_people_the_prompt(): void
    {
        $prompt = 'Read '.route('agents').', install the exposehub agent if it is missing, then give my local site a public address.';

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="overview-agents"', false)
            ->assertSee($prompt)
            ->assertSee('claude mcp add exposehub -- exposehub mcp');

        $this->get(route('docs'))
            ->assertOk()
            ->assertSee('id="agents"', false)
            ->assertSee($prompt)
            ->assertSee('exposehub http ./dist --subdomain preview')
            ->assertSee('<code>{"command": "exposehub", "args": ["mcp"]}</code>', false);
    }
}
