<?php

namespace App\Services;

use App\Mail\EmployeeRegistrationLinkMail;
use App\Models\Employee;
use App\Models\EmployeeRegistrationCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input, Request $request): Employee
    {
        return DB::transaction(function () use ($actor, $input, $request): Employee {
            $employee = Employee::query()->create($input);

            $this->auditLogService->log(
                actor: $actor,
                action: 'employee.created',
                entityType: 'employee',
                entityId: $employee->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'employee_public_id' => $employee->public_id,
                    'employee_no' => $employee->employee_no,
                    'idempotency_key' => $request->header('X-Idempotency-Key'),
                ],
                request: $request,
            );

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, Employee $employee, array $input, Request $request): Employee
    {
        return DB::transaction(function () use ($actor, $employee, $input, $request): Employee {
            $employee->update($input);

            $this->auditLogService->log(
                actor: $actor,
                action: 'employee.updated',
                entityType: 'employee',
                entityId: $employee->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'employee_public_id' => $employee->public_id,
                ],
                request: $request,
            );

            return $employee;
        });
    }

    /**
     * @return array{plain_code:string, model:EmployeeRegistrationCode}
     */
    private function issueRegistrationCode(User $actor, Employee $employee, ?string $expiresAt, Request $request): array
    {
        return DB::transaction(function () use ($actor, $employee, $expiresAt, $request): array {
            $plain = Str::upper(Str::random(8));

            $code = EmployeeRegistrationCode::query()->updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'code_hash' => Hash::make($plain),
                    'expires_at' => $expiresAt,
                    'used_at' => null,
                    'issued_by' => $actor->id,
                ],
            );

            $this->auditLogService->log(
                actor: $actor,
                action: 'registration_code.issued',
                entityType: 'employee',
                entityId: $employee->id,
                meta: [
                    'request_id' => $request->attributes->get('request_id'),
                    'employee_public_id' => $employee->public_id,
                    'expires_at' => $expiresAt,
                ],
                request: $request,
            );

            return ['plain_code' => $plain, 'model' => $code];
        });
    }

    public function sendRegistrationLink(User $actor, Employee $employee, ?string $expiresAt, Request $request): void
    {
        $activeAccountExists = User::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->exists();

        if ($activeAccountExists) {
            throw ValidationException::withMessages([
                'employee' => 'Employee account is already active.',
            ]);
        }

        if (blank($employee->email)) {
            throw ValidationException::withMessages([
                'employee' => 'Employee email is required to send a registration link.',
            ]);
        }

        $result = $this->issueRegistrationCode($actor, $employee, $expiresAt, $request);

        $registrationUrl = route('auth.register', [
            'employee_no' => $employee->employee_no,
            'registration_code' => $result['plain_code'],
        ]);

        Mail::to($employee->email)->send(new EmployeeRegistrationLinkMail(
            employee: $employee,
            registrationUrl: $registrationUrl,
            registrationCode: $result['plain_code'],
        ));

        $this->auditLogService->log(
            actor: $actor,
            action: 'registration_link.sent',
            entityType: 'employee',
            entityId: $employee->id,
            meta: [
                'request_id' => $request->attributes->get('request_id'),
                'employee_public_id' => $employee->public_id,
                'employee_no' => $employee->employee_no,
                'email' => $employee->email,
                'expires_at' => $expiresAt,
            ],
            request: $request,
        );
    }
}
