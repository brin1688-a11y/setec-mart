<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The link that lets someone choose a new password.
 *
 * Queued for the same reason as QueuedVerifyEmail — and because the reply to
 * the customer deliberately does not depend on whether this went out, which
 * is what lets it say the same thing for a known and an unknown address.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Choose a new password · '.config('app.name'))
            ->greeting('Forgotten your password?')
            ->line('It happens. Use the button below to choose a new one.')
            ->action('Choose a new password', $url)
            ->line('The link expires in '.$minutes.' minutes.')
            ->line('If you did not ask for this, you can ignore this email — your password has not changed and nobody can change it without this link.')
            ->salutation("Thanks,\n".config('app.name'));
    }
}
