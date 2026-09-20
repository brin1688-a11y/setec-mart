<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The link that confirms a new customer's address.
 *
 * Queued: sent inline it would make registering wait on an HTTP call to the
 * mail provider, and a provider having a bad minute would turn a signup into
 * a server error with the account already created.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email · '.config('app.name'))
            ->greeting('Welcome to '.config('app.name').'!')
            ->line('One tap and your account is ready. We ask so we can reach you about a delivery — and so nobody can order in your name.')
            ->action('Confirm my email', $url)
            ->line('The link is good for 60 minutes. You can keep browsing and filling your basket meanwhile; only placing the order waits for this.')
            ->salutation("See you in the aisles,\n".config('app.name'));
    }
}
