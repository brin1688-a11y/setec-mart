<?php

namespace App\Rules;

use App\Support\Cambodia;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts 012 345 678, 012345678, +855 12 345 678 and 85512345678 alike.
 */
class CambodianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Cambodia::isValidPhone(is_string($value) ? $value : null)) {
            $fail('Please enter a valid Cambodian phone number, for example 012 345 678.');
        }
    }
}
