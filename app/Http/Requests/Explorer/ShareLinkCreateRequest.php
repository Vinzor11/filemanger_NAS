<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class ShareLinkCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('share.link.create') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_downloads' => ['nullable', 'integer', 'min:1'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }
}

