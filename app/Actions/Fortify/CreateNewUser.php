<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'email' => $input['email'] ?? null,
            'password_hash' => $input['password'],
            'status' => 'pending',
        ]);
    }
}
