<?php

namespace App\Console\Commands;

use App\Models\AgentToken;
use App\Models\UserIdentity;
use App\Notifications\Account\AccountNotice;
use App\Support\AccountMail;
use App\Support\Sso\Sso;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sso:remind')]
#[Description('Mail people whose agent tokens stop working soon because they have not signed in through the identity provider for a while')]
class RemindOfExpiringTokens extends Command
{
    /** How many days before the end the mail goes out. */
    private const int DAYS_AHEAD = 3;

    public function handle(): int
    {
        if (! Sso::enabled()) {
            // Switched on again later, the days of accounts that never signed in start anew.
            Sso::forgetSince();
            $this->info('Single sign-on is off.');

            return self::SUCCESS;
        }
        // Notes since when it is on, so the snapshot never has to.
        Sso::since();

        $days = Sso::tokenDays();
        $cutoff = Sso::tokenCutoff();
        if ($cutoff === null || $days <= self::DAYS_AHEAD) {
            $this->info('Tokens are not limited, or for too short a time to warn ahead.');

            return self::SUCCESS;
        }

        $reminded = 0;
        UserIdentity::query()
            ->where('issuer', Sso::issuer())
            ->where('signed_in_at', '>=', $cutoff)
            ->where('signed_in_at', '<', $cutoff->copy()->addDays(self::DAYS_AHEAD))
            // Once per sign-in: a later sign-in clears the note.
            ->whereNull('reminded_at')
            ->with('user')
            ->chunkById(200, function ($identities) use ($days, &$reminded) {
                foreach ($identities as $identity) {
                    if (! AgentToken::query()->where('user_id', $identity->user_id)->usable()->exists()) {
                        continue;
                    }
                    $left = max(1, (int) ceil(now()->diffInHours($identity->signed_in_at->copy()->addDays($days), false) / 24));
                    AccountMail::send($identity->user, AccountNotice::tokensExpiring(Sso::name(), $left));
                    $identity->update(['reminded_at' => now()]);
                    $reminded++;
                }
            });

        $this->info("Reminded {$reminded} people.");

        return self::SUCCESS;
    }
}
