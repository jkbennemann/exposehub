<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * No other site may show the dashboard in a frame (clickjacking).
 */
class FramingHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_may_not_be_framed(): void
    {
        $user = User::factory()->create();

        foreach ([
            $this->get('/'),
            $this->get(route('login')),
            $this->actingAs($user)->get(route('dashboard', ['current_team' => $user->currentTeam->slug])),
            $this->actingAs($user)->get('/no-such-page'),
        ] as $response) {
            $response->assertHeader('X-Frame-Options', 'DENY');
            $this->assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        }
    }
}
