<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\EmployeeRegistrationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClaimRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function issueCode(Employee $employee, string $plainCode = 'ABC12345'): void
    {
        EmployeeRegistrationCode::query()->create([
            'employee_id' => $employee->id,
            'code_hash' => Hash::make($plainCode),
            'expires_at' => now()->addHour(),
            'used_at' => null,
            'issued_by' => null,
        ]);
    }

    public function test_employee_can_claim_existing_record_and_be_marked_pending(): void
    {
        $employee = Employee::factory()->create([
            'employee_no' => 'EMP-9001',
            'status' => 'active',
        ]);
        $this->issueCode($employee, 'REG90010');

        $response = $this->post(route('auth.register.store'), [
            'employee_no' => 'EMP-9001',
            'registration_code' => 'REG90010',
            'email' => 'claimant@example.com',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
        ]);

        $response->assertRedirect(route('auth.register.pending'));
        $this->assertDatabaseHas('users', [
            'employee_id' => $employee->id,
            'email' => 'claimant@example.com',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'registration.claim_submitted',
            'entity_type' => 'user',
        ]);
    }

    public function test_employee_claim_validates_registration_code_when_provided(): void
    {
        $employee = Employee::factory()->create([
            'employee_no' => 'EMP-0099',
            'status' => 'active',
        ]);
        $this->issueCode($employee);

        $response = $this->post(route('auth.register.store'), [
            'employee_no' => 'EMP-0099',
            'registration_code' => 'ABC12345',
            'email' => 'coded@example.com',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
        ]);

        $response->assertRedirect(route('auth.register.pending'));

        $code = EmployeeRegistrationCode::query()
            ->where('employee_id', $employee->id)
            ->firstOrFail();
        $this->assertNotNull($code->used_at);
    }

    public function test_claim_fails_for_unknown_employee_number(): void
    {
        $response = $this->post(route('auth.register.store'), [
            'employee_no' => 'NOT-FOUND',
            'registration_code' => 'ANYCODE1',
            'email' => 'nobody@example.com',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
        ]);

        $response->assertSessionHasErrors('employee_no');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_claim_fails_when_employee_already_claimed(): void
    {
        $employee = Employee::factory()->create([
            'employee_no' => 'EMP-7788',
            'status' => 'active',
        ]);
        User::factory()->create([
            'employee_id' => $employee->id,
            'email' => 'existing@example.com',
            'status' => 'active',
        ]);

        $response = $this->post(route('auth.register.store'), [
            'employee_no' => 'EMP-7788',
            'registration_code' => 'ANYCODE2',
            'email' => 'new@example.com',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
        ]);

        $response->assertSessionHasErrors('employee_no');
    }

    public function test_claim_requires_registration_code(): void
    {
        $employee = Employee::factory()->create([
            'employee_no' => 'EMP-1234',
            'status' => 'active',
        ]);

        $response = $this->post(route('auth.register.store'), [
            'employee_no' => $employee->employee_no,
            'email' => 'missing-code@example.com',
            'password' => 'StrongPassword!123',
            'password_confirmation' => 'StrongPassword!123',
        ]);

        $response->assertSessionHasErrors('registration_code');
        $this->assertDatabaseCount('users', 0);
    }
}
