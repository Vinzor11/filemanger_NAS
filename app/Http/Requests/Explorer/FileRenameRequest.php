<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class FileRenameRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('files.update') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'original_name' => ['required', 'string', 'max:255', 'not_regex:/[\/\\\\]/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'original_name' => trim((string) $this->input('original_name', '')),
        ]);
    }
}
