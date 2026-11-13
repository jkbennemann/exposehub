<?php

namespace App\Console\Commands;

use App\Support\Demo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:reset')]
#[Description('Delete the demo team with its visitors, tokens and request logs, and lay the sample data out again')]
class ResetDemo extends Command
{
    public function handle(): int
    {
        Demo::reset();

        $this->info(Demo::enabled()
            ? 'The demo was wiped and laid out again.'
            : 'The demo is off (EXPOSEHUB_DEMO); what was left of it is deleted.');

        return self::SUCCESS;
    }
}
