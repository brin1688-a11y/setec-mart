<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's own verification email, handed to the queue.
 *
 * Sent inline it would make registering wait on an HTTP call to the mail
 * provider, and a provider having a bad minute would turn a signup into a
 * server error — with the account already created.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
