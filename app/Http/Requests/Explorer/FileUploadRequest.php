<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FileUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('files.upload') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'folder_id' => ['required', 'uuid', 'exists:folders,public_id'],
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimetypes:text/plain,application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'duplicate_mode' => ['nullable', Rule::in(['fail', 'replace', 'auto_rename'])],
            'original_name' => ['nullable', 'string', 'max:255', 'not_regex:/[\/\\\\]/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $folderId = $this->input('folder_id');
        $folderUuid = $this->input('folder_uuid');

        $this->merge([
            'folder_id' => $folderId ?: $folderUuid,
            'original_name' => $this->filled('original_name') ? trim((string) $this->input('original_name')) : null,
        ]);
    }
}
