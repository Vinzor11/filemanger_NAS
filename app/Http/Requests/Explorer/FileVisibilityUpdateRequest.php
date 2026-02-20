<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FileVisibilityUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('files.update') === true;
    }

    public function rules(): array
    {
        return [
            'visibility' => ['required', Rule::in(['private', 'department', 'shared'])],
        ];
    }
}
