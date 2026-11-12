<?php

namespace Tests\Feature\Legal;

use App\Models\User;
use App\Rules\TeamName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/exposehub-legal-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->dir);
        config(['exposehub.legal_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_legal_pages_are_absent_until_the_operator_provides_them(): void
    {
        $this->get('/imprint')->assertNotFound();
        $this->get('/privacy')->assertNotFound();

        $this->get(route('home'))->assertOk()->assertDontSee('Imprint')->assertDontSee('Privacy');
    }

    public function test_a_provided_text_is_published_and_linked(): void
    {
        File::put($this->dir.'/imprint.md', "# Imprint\n\nExample Operator  \nSome Street 1");

        $this->get('/imprint')
            ->assertOk()
            ->assertSee('<h1>Imprint</h1>', false)
            ->assertSee('Example Operator');

        // Only the page that exists is linked.
        $this->get(route('home'))->assertSee(route('legal.imprint'))->assertDontSee(route('legal.privacy'));
        $this->get(route('login'))->assertSee(route('legal.imprint'));
        $this->get('/privacy')->assertNotFound();
    }

    public function test_signed_in_pages_link_to_the_legal_texts(): void
    {
        File::put($this->dir.'/privacy.md', '# Privacy notice');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard', ['current_team' => $user->currentTeam->slug]))
            ->assertOk()
            ->assertSee(route('legal.privacy'));
    }

    public function test_html_in_a_legal_text_is_not_passed_through(): void
    {
        File::put($this->dir.'/imprint.md', "# Imprint\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))");

        $this->get('/imprint')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('javascript:alert', false);
    }

    public function test_a_team_cannot_take_the_name_of_a_legal_page(): void
    {
        foreach (['imprint', 'privacy'] as $name) {
            $this->assertTrue(
                Validator::make(['name' => $name], ['name' => [new TeamName]])->fails(),
                "A team named {$name} would shadow the /{$name} page.",
            );
        }
    }
}
