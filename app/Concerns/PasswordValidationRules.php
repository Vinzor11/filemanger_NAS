<?php

namespace App\Concerns;

use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return [
            'required',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                $user = method_exists($this, 'user') ? $this->user() : null;
                $candidate = (string) $value;
                $stored = is_object($user) ? ($user->password_hash ?? null) : null;

                if (! is_string($stored) || $stored === '') {
                    $fail('The current password is incorrect.');

                    return;
                }

                $isHashed = Hash::isHashed($stored);
                $matches = $isHashed
                    ? password_verify($candidate, $stored)
                    : hash_equals($stored, $candidate);

                if (! $matches) {
                    $fail('The current password is incorrect.');
                }
            },
        ];
    }
}
