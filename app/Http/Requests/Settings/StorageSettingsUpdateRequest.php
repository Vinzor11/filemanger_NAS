<?php

namespace App\Http\Requests\Settings;

use App\Services\StorageDiskResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorageSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') === true
            || $this->user()?->can('users.assign_roles') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'storage_disk' => [
                'required',
                'string',
                Rule::in(app(StorageDiskResolver::class)->availableDisks()),
            ],
        ];
    }
}
