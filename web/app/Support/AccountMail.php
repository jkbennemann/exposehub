<?php

namespace App\Support;

use App\Notifications\Account\AccountNotice;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends account notices without letting a mail failure undo or block the
 * change they report: a password is changed whether or not the mail about it
 * goes out. Failures are reported to the error log.
 */
final class AccountMail
{
    /**
     * @param  object|string  $to  a notifiable, or an address for someone who has no account (any more)
     */
    public static function send(object|string $to, AccountNotice $notice): void
    {
        try {
            if (is_string($to)) {
                Notification::route('mail', $to)->notify($notice);
            } else {
                Notification::send($to, $notice);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
