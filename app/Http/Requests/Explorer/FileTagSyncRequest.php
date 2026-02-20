<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class FileTagSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tags.manage') === true;
    }

    public function rules(): array
    {
        return [
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['integer', 'exists:tags,id'],
        ];
    }
}
