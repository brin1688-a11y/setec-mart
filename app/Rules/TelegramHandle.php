<?php

namespace App\Rules;

use App\Support\Cambodia;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Telegram username, with or without the @, or a t.me link.
 */
class TelegramHandle implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        if (Cambodia::normaliseTelegram(is_string($value) ? $value : null) === null) {
            $fail('That does not look like a Telegram username (5–32 letters, numbers or underscores).');
        }
    }
}
