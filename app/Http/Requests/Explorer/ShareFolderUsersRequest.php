<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class ShareFolderUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shares' => ['required', 'array', 'min:1'],
            'shares.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'shares.*.can_view' => ['sometimes', 'boolean'],
            'shares.*.can_upload' => ['sometimes', 'boolean'],
            'shares.*.can_edit' => ['sometimes', 'boolean'],
            'shares.*.can_delete' => ['sometimes', 'boolean'],
        ];
    }
}
