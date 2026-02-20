<?php

namespace App\Http\Requests\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('employees.manage') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $employee = $this->route('employee');

        return [
            'employee_no' => [
                'required',
                'string',
                'max:30',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('employees', 'employee_no')->ignore($employee?->id),
            ],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'position_id' => [
                'nullable',
                'integer',
                Rule::exists('positions', 'id')->where(function (Builder $query): void {
                    $departmentId = $this->input('department_id');

                    if (! is_numeric($departmentId)) {
                        return;
                    }

                    $query->where(function (Builder $scope) use ($departmentId): void {
                        $scope
                            ->whereNull('department_id')
                            ->orWhere('department_id', (int) $departmentId);
                    });
                }),
            ],
            'position_title' => ['nullable', 'string', 'max:120'],
            'first_name' => ['required', 'string', 'max:80', 'regex:/^[\pL\s\'\.-]+$/u'],
            'middle_name' => ['nullable', 'string', 'max:80', 'regex:/^[\pL\s\'\.-]+$/u'],
            'last_name' => ['required', 'string', 'max:80', 'regex:/^[\pL\s\'\.-]+$/u'],
            'email' => ['nullable', 'email', 'max:150'],
            'mobile' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'status' => ['required', Rule::in(['active', 'inactive', 'resigned'])],
            'hired_at' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'employee_no.regex' => 'Employee number may only contain letters, numbers, dot, underscore, and hyphen.',
            'position_id.exists' => 'Selected position is not valid for the selected department.',
            'first_name.regex' => 'First name contains invalid characters.',
            'middle_name.regex' => 'Middle name contains invalid characters.',
            'last_name.regex' => 'Last name contains invalid characters.',
            'mobile.regex' => 'Mobile number format is invalid.',
            'hired_at.before_or_equal' => 'Hired date cannot be in the future.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $employeeNo = strtoupper(trim((string) $this->input('employee_no')));

        $this->merge([
            'employee_no' => $employeeNo,
            'first_name' => $this->normalizeName($this->input('first_name')),
            'middle_name' => $this->normalizeName($this->input('middle_name')),
            'last_name' => $this->normalizeName($this->input('last_name')),
            'position_title' => $this->normalizeNullableString($this->input('position_title')),
            'email' => $this->normalizeEmail($this->input('email')),
            'mobile' => $this->normalizeMobile($this->input('mobile')),
        ]);
    }

    private function normalizeName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_replace('/\s+/', ' ', trim($value));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeMobile(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
