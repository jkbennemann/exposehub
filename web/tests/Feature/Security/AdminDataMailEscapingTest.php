<?php

namespace Tests\Feature\Security;

use App\Models\Team;
use App\Models\TeamInvitation as TeamInvitationModel;
use App\Models\User;
use App\Notifications\Account\AccountNotice;
use App\Notifications\Teams\TeamInvitation;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * Names people choose stay text in mail: no Markdown link, image or HTML
 * planted in a name reaches the mail as markup.
 */
class AdminDataMailEscapingTest extends TestCase
{
    use RefreshDatabase;

    private const string TEAM = 'Acme [Verify your login](https://evil.example/team)';

    private const string PERSON = 'Eve ![x](https://evil.example/pixel.png) <a href="https://evil.example/html">here</a> [Reset](https://evil.example/user)';

    protected function tearDown(): void
    {
        Markdown::withoutSecuredEncoding();

        parent::tearDown();
    }

    /**
     * The link and image addresses of the mail's actual elements.
     *
     * @return array<int, string>
     */
    private function urls(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        $urls = [];
        foreach ((new DOMXPath($dom))->query('//@href | //@src') ?: [] as $attribute) {
            $urls[] = $attribute->nodeValue;
        }

        return $urls;
    }

    private function html(MailMessage $mail): string
    {
        return (string) $mail->render();
    }

    private function text(MailMessage $mail): string
    {
        return (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
    }

    private function invitationMail(): MailMessage
    {
        $inviter = User::factory()->create(['name' => self::PERSON]);
        $team = Team::factory()->create(['name' => self::TEAM]);
        $invitation = TeamInvitationModel::factory()->create(['team_id' => $team->id, 'invited_by' => $inviter->id, 'email' => 'someone@outside.example']);

        return (new TeamInvitation($invitation))->toMail(new AnonymousNotifiable);
    }

    public function test_an_invitation_keeps_team_and_inviter_names_as_text(): void
    {
        $mail = $this->invitationMail();
        $html = $this->html($mail);

        $this->assertEmpty(array_filter($this->urls($html), fn ($url) => str_contains($url, 'evil.example')));
        $this->assertNotEmpty(array_filter($this->urls($html), fn ($url) => str_contains($url, '/login?invitation=')));
        $this->assertStringNotContainsString('<a href="https://evil.example', $html);
        $this->assertStringContainsString('[Verify your login](https://evil.example/team)', html_entity_decode(strip_tags($html)));

        // The plain-text part shows the names as typed, without escapes.
        $text = $this->text($mail);
        $this->assertStringContainsString(self::PERSON.' has invited you to join the '.self::TEAM.' team.', $text);
    }

    public function test_account_notices_keep_names_in_greeting_and_lines_as_text(): void
    {
        $team = Team::factory()->create(['name' => self::TEAM]);
        $recipient = User::factory()->create(['name' => 'Max [Open](https://evil.example/greeting)']);

        foreach ([
            AccountNotice::logsShared(self::PERSON, $team),
            AccountNotice::agentTokenRevoked('[Token](https://evil.example/token)', $team, self::PERSON),
            AccountNotice::accountSuspended('[Appeal here](https://evil.example/reason)'),
            AccountNotice::passkeyAdded('[Key](https://evil.example/passkey)'),
            AccountNotice::teamDeleted(self::TEAM, self::PERSON),
        ] as $notice) {
            $html = $this->html($notice->toMail($recipient));

            $this->assertEmpty(array_filter($this->urls($html), fn ($url) => str_contains($url, 'evil.example')), $notice->kind);
            $this->assertStringContainsString('Max [Open](https://evil.example/greeting)', html_entity_decode(strip_tags($html)), $notice->kind);
        }
    }

    public function test_names_are_not_escaped_twice_with_laravels_secured_encoding(): void
    {
        Markdown::withSecuredEncoding();

        $html = $this->html($this->invitationMail());

        $this->assertEmpty(array_filter($this->urls($html), fn ($url) => str_contains($url, 'evil.example')));
        $this->assertStringContainsString('[Verify your login](https://evil.example/team)', html_entity_decode(strip_tags($html)));
        $this->assertStringNotContainsString('\\[', html_entity_decode(strip_tags($html)));
    }
}
