<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's own reset email, handed to the queue — same reason as
 * QueuedVerifyEmail. The reply to the customer does not depend on the mail
 * going out, which is also why it can say the same thing either way.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
