<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class ShareUsersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('share.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shares' => ['required', 'array', 'min:1'],
            'shares.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'shares.*.can_view' => ['sometimes', 'boolean'],
            'shares.*.can_download' => ['sometimes', 'boolean'],
            'shares.*.can_edit' => ['sometimes', 'boolean'],
            'shares.*.can_delete' => ['sometimes', 'boolean'],
        ];
    }
}

