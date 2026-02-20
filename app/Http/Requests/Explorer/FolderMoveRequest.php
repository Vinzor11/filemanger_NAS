<?php

namespace App\Http\Requests\Explorer;

use Illuminate\Foundation\Http\FormRequest;

class FolderMoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('folders.update') === true;
    }

    public function rules(): array
    {
        return [
            'destination_folder_id' => ['required', 'uuid', 'exists:folders,public_id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $destinationFolderId = $this->input('destination_folder_id');
        $destinationFolderUuid = $this->input('destination_folder_uuid');

        $this->merge([
            'destination_folder_id' => $destinationFolderId ?: $destinationFolderUuid,
        ]);
    }
}
