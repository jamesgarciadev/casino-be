<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Password policy for new passwords. Mirrored in casino-web's registerSchema; keep both in sync.
 */
final class StrongPassword implements ValidationRule
{
    public const MIN = 8;

    public const MAX = 64;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Password must be a string.');

            return;
        }

        $length = mb_strlen($value);

        if ($length < self::MIN || $length > self::MAX) {
            $fail('Password must be between '.self::MIN.' and '.self::MAX.' characters.');
        }

        if (preg_match('/\s/u', $value)) {
            $fail('Password must not contain spaces.');
        }

        if (! preg_match('/\p{Lu}/u', $value)) {
            $fail('Password must contain at least one uppercase letter.');
        }

        if (! preg_match('/\p{Ll}/u', $value)) {
            $fail('Password must contain at least one lowercase letter.');
        }

        if (! preg_match('/\d/', $value)) {
            $fail('Password must contain at least one number.');
        }

        if (! preg_match('/[^\p{L}\d\s]/u', $value)) {
            $fail('Password must contain at least one special character.');
        }
    }
}
