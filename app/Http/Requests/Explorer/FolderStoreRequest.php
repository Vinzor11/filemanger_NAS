<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FolderStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('folders.create') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'uuid', 'exists:folders,public_id'],
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\/\\\\]/'],
            'scope' => ['nullable', Rule::in(['private', 'department'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $parentId = $this->input('parent_id');
        $parentUuid = $this->input('parent_uuid');

        $this->merge([
            'parent_id' => $parentId ?: $parentUuid,
            'name' => trim((string) $this->input('name', '')),
            'scope' => $this->filled('scope') ? strtolower((string) $this->input('scope')) : null,
        ]);
    }
}
