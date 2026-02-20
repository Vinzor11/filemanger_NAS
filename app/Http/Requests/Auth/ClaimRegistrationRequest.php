<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ClaimRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_no' => ['required', 'string', 'max:30'],
            'registration_code' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
