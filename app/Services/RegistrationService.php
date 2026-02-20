<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RegistrationService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function claimEmployee(array $input, Request $request): User
    {
        return DB::transaction(function () use ($input, $request): User {
            $employee = Employee::query()
                ->where('employee_no', $input['employee_no'])
                ->lockForUpdate()
                ->first();

            if (! $employee) {
                throw ValidationException::withMessages([
                    'employee_no' => 'Employee number is not recognized.',
                ]);
            }

            if ($employee->status !== 'active') {
                throw ValidationException::withMessages([
                    'employee_no' => 'Employee is not active and cannot register.',
                ]);
            }

            if (User::query()->where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages([
                    'employee_no' => 'This employee has already been claimed.',
                ]);
            }

            $code = $employee->registrationCode()->lockForUpdate()->first();

            if (! $code || $code->used_at !== null || ($code->expires_at !== null && now()->greaterThan($code->expires_at))) {
                throw ValidationException::withMessages([
                    'registration_code' => 'Registration code is invalid or expired.',
                ]);
            }

            if (! Hash::check($input['registration_code'], $code->code_hash)) {
                throw ValidationException::withMessages([
                    'registration_code' => 'Registration code does not match.',
                ]);
            }

            $code->update(['used_at' => now()]);

            $user = User::query()->create([
                'employee_id' => $employee->id,
                'email' => $input['email'] ?? null,
                'password_hash' => $input['password'],
                'status' => 'pending',
            ]);

            $this->auditLogService->log(
                actor: $user,
                action: 'registration.claim_submitted',
                entityType: 'user',
                entityId: $user->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'employee_no' => $employee->employee_no,
                    'employee_public_id' => $employee->public_id,
                ],
                request: $request,
            );

            return $user;
        });
    }
}
