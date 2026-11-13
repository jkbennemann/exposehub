<?php

namespace App\Actions\Sso;

use App\Models\SsoConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Sso\Sso;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveTeamConnection
{
    /** How many email domains one team's provider may ask for. */
    private const int MAX_DOMAINS = 10;

    /**
     * Enter or change a team's identity provider.
     *
     * Nothing here contacts the provider: the address is whatever the team's
     * owner typed, and the server only talks to it once a platform admin has
     * approved the connection. Changing what the provider is called, who it
     * is, which domains it answers for, or whether unconfirmed addresses are
     * taken withdraws an approval; changing only the client's id or secret
     * does not.
     *
     * The caller has checked that $by may manage the team's single sign-on.
     *
     * @param  array{name?: mixed, issuer?: mixed, client_id?: mixed, client_secret?: mixed, domains?: mixed, require_verified_email?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function handle(Team $team, User $by, array $input): SsoConnection
    {
        $existing = SsoConnection::query()->where('team_id', $team->id)->first();
        $input['domains'] = is_string($input['domains'] ?? null)
            ? Sso::normalizeDomains((array) preg_split('/[\s,;]+/', $input['domains']))
            : [];

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:60', 'regex:/^[^\x00-\x1F<>]+$/u'],
            'issuer' => ['required', 'string', 'max:255', $this->aPublicHttpsAddress(...)],
            'client_id' => ['required', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            // Never shown again once saved; left empty, the stored one stays.
            'client_secret' => [$existing === null ? 'required' : 'nullable', 'string', 'max:1000'],
            'domains' => ['required', 'array', 'min:1', 'max:'.self::MAX_DOMAINS],
            'domains.*' => ['string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', 'not_in:demo.invalid'],
            'require_verified_email' => ['boolean'],
        ], [
            'name.regex' => __('Use plain text for the name.'),
            'domains.required' => __('Name at least one email domain, such as example.com.'),
            'domains.max' => __('A team\'s provider can answer for up to :max domains.'),
            'domains.*.regex' => __('":input" is not an email domain. Write it like example.com.'),
            'domains.*.not_in' => __('That domain is not one a provider can answer for.'),
        ])->validate();

        return DB::transaction(function () use ($team, $by, $existing, $data) {
            $connection = $existing ?? new SsoConnection(['team_id' => $team->id, 'created_by' => $by->id]);
            $connection->fill([
                'name' => trim($data['name']),
                'issuer' => trim($data['issuer']),
                'client_id' => trim($data['client_id']),
                'domains' => $data['domains'],
                'require_verified_email' => (bool) ($data['require_verified_email'] ?? true),
            ]);
            if (filled($data['client_secret'] ?? null)) {
                $connection->client_secret = $data['client_secret'];
            }

            // What an admin approved was a provider of this name at this issuer,
            // answering for these domains on these terms.
            if ($connection->isDirty(SsoConnection::APPROVED)) {
                $connection->forceFill(['approved_at' => null, 'approved_by' => null]);
            }
            $connection->save();

            return $connection;
        });
    }

    /**
     * The issuer has to be an https address on a public host name. The
     * server will fetch from it once the connection is approved, and an
     * approval should not be the only thing between a team's owner and
     * addresses inside the network this service runs in.
     */
    private function aPublicHttpsAddress(string $attribute, mixed $value, callable $fail): void
    {
        $parts = is_string($value) ? parse_url(trim($value)) : false;
        $host = is_array($parts) ? Str::lower((string) ($parts['host'] ?? '')) : '';

        $ok = is_array($parts)
            && Str::lower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443)
            && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1
            && ! Str::endsWith($host, ['.local', '.internal', '.localhost', '.lan', '.home', '.svc', '.cluster.local', '.test', '.invalid', '.example']);

        if (! $ok) {
            $fail(__('Enter the issuer as your provider shows it: an https address on a public host name, such as https://example.okta.com'));
        }
    }
}
