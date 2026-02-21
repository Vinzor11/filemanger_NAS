<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class ShareUserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'can_view' => ['sometimes', 'boolean'],
            'can_download' => ['sometimes', 'boolean'],
            'can_edit' => ['sometimes', 'boolean'],
            'can_delete' => ['sometimes', 'boolean'],
        ];
    }
}
