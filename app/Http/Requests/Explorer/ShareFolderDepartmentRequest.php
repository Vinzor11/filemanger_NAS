<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class ShareFolderDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('share.manage') === true;
    }

    public function rules(): array
    {
        return [
            'can_view' => ['sometimes', 'boolean'],
            'can_upload' => ['sometimes', 'boolean'],
            'can_edit' => ['sometimes', 'boolean'],
            'can_delete' => ['sometimes', 'boolean'],
        ];
    }
}

