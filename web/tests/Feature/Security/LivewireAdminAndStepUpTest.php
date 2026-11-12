<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Livewire sends every action of a page to one shared update URL, so the
 * page's own route middleware (admin, password confirmation) has to be applied
 * again there, and the components check the admin role themselves too.
 */
class LivewireAdminAndStepUpTest extends TestCase
{
    use RefreshDatabase;
    use ReplaysLivewireSnapshots;

    protected function setUp(): void
    {
        parent::setUp();

        config(['exposehub.admin_emails' => ['owner@example.com']]);
        Notification::fake();
    }

    private function grantedAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function retention(): mixed
    {
        return DB::table('settings')->where('key', 'log_retention_days')->value('value');
    }

    private function settingsSnapshot(User $admin): string
    {
        $html = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.settings'))->assertOk()->getContent();

        return $this->snapshotOf($html, 'pages::admin.settings');
    }

    public function test_an_expired_password_confirmation_stops_livewire_actions_whatever_the_accept_header(): void
    {
        $admin = $this->grantedAdmin();
        $snapshot = $this->settingsSnapshot($admin);
        $expired = time() - 20000;

        foreach ([
            ['Accept' => 'application/json'],
            ['Accept' => '*/*', 'X-Requested-With' => 'XMLHttpRequest'],
            ['Accept' => '*/*'],
        ] as $headers) {
            $this->withSession(['auth.password_confirmed_at' => $expired]);
            $response = $this->replay($snapshot, ['retention' => '12'], [['save']], $headers);

            $this->assertContains($response->status(), [302, 423], 'Headers: '.json_encode($headers));
            $this->assertNull($this->retention(), 'Saved with headers: '.json_encode($headers));
        }

        // With a fresh confirmation the same request goes through.
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->replay($snapshot, ['retention' => '14'], [['save']])->assertOk();
        $this->assertSame('14', $this->retention());
    }

    public function test_the_fortify_json_routes_still_answer_423_without_a_recent_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time() - 20000])
            ->getJson('/user/passkeys/options')
            ->assertStatus(423);
    }

    public function test_a_revoked_admin_cannot_replay_admin_snapshots(): void
    {
        $admin = $this->grantedAdmin();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        $settings = $this->snapshotOf($this->get(route('admin.settings'))->assertOk()->getContent(), 'pages::admin.settings');
        $people = $this->snapshotOf($this->get(route('admin.people'))->assertOk()->getContent(), 'pages::admin.people');

        $admin->forceFill(['is_admin' => false])->save();
        User::factory()->create(['email' => 'someone-new@example.org']);

        $this->replay($settings, ['allowed' => 'evil.example', 'retention' => '2'], [['save']])->assertNotFound();
        $this->assertSame(0, DB::table('settings')->count());

        $response = $this->replay($people, ['q' => 'someone-new']);
        $response->assertNotFound();
        $this->assertStringNotContainsString('someone-new@example.org', $response->getContent());
    }

    public function test_livewire_reapplies_the_admin_middleware_to_components_without_their_own_check(): void
    {
        // The team switcher in the page header has no admin check of its own,
        // so only the re-applied route middleware can refuse it here.
        $admin = $this->grantedAdmin();
        $html = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('admin.settings'))->assertOk()->getContent();
        $switcher = $this->snapshotOf($html, 'team-switcher');

        $this->replay($switcher)->assertOk();

        $admin->forceFill(['is_admin' => false])->save();

        $this->replay($switcher)->assertNotFound();
    }

    public function test_every_admin_component_checks_the_role_itself(): void
    {
        // Livewire::test() skips the route and Livewire's persistent middleware,
        // so this checks the components' own guard.
        $admin = $this->grantedAdmin();
        $settings = Livewire::actingAs($admin)->test('pages::admin.settings');

        $admin->forceFill(['is_admin' => false])->save();

        $settings->call('save')->assertNotFound();
        $this->assertNull($this->retention());

        foreach (['overview', 'people', 'teams', 'tunnels', 'settings', 'audit'] as $page) {
            Livewire::actingAs($admin->fresh())->test("pages::admin.{$page}")->assertNotFound();
        }
        Livewire::actingAs($admin->fresh())->test('pages::admin.person', ['user' => User::factory()->create()])->assertNotFound();
    }
}
