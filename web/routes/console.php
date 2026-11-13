<?php

use App\Models\AdminAction;
use App\Models\TeamInvitation;
use App\Models\TunnelDisconnect;
use App\Support\Demo;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::call(function () {
    AdminAction::query()
        ->where('created_at', '<', now()->subDays((int) config('exposehub.admin_audit_retention_days')))
        ->delete();
})->daily()->description('Delete admin audit log entries past their retention');

Schedule::command('logs:prune')->hourly()->description('Delete request logs older than the retention period');

Schedule::call(fn () => TunnelDisconnect::query()->expired()->delete())
    ->everyFiveMinutes()->description('Delete requests to disconnect a tunnel that the tunnel server no longer needs');

// The demo wipes itself a fixed time after it was laid out, so the check runs
// every minute while it is on. Switched off, an hourly look clears what is left.
$demo = Schedule::command('demo:reset')->when(fn () => Demo::due())
    ->description('Wipe the demo and lay its sample data out again when its time is up');
Demo::enabled() ? $demo->everyMinute() : $demo->hourly();

Schedule::command('sso:remind')->dailyAt('07:30')
    ->description('Warn people whose agent tokens stop working soon for want of a sign-in through the identity provider');
