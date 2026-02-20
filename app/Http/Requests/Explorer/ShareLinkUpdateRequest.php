<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class ShareLinkUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('share.link.create') === true;
    }

    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_downloads' => ['nullable', 'integer', 'min:1'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }
}
