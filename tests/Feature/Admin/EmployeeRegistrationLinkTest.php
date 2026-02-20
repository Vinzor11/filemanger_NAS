<?php

namespace Tests\Feature\Admin;

use App\Mail\EmployeeRegistrationLinkMail;
use App\Models\Employee;
use App\Models\EmployeeRegistrationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class EmployeeRegistrationLinkTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_admin_can_send_registration_link_to_employee_email(): void
    {
        Mail::fake();

        $admin = $this->createUser();
        $this->grantPermissions($admin, ['registration_codes.manage']);

        $employee = Employee::factory()->create([
            'employee_no' => 'EMP-4455',
            'email' => 'employee4455@example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/employees')
            ->post(route('admin.employees.registration-link.send', $employee->public_id), []);

        $response->assertRedirect('/admin/employees');
        $response->assertSessionHas('status', 'Registration link sent to employee email.');

        $sentCode = null;

        Mail::assertSent(EmployeeRegistrationLinkMail::class, function (EmployeeRegistrationLinkMail $mail) use ($employee, &$sentCode): bool {
            $sentCode = $mail->registrationCode;

            $parsedUrl = parse_url($mail->registrationUrl);
            parse_str($parsedUrl['query'] ?? '', $query);

            return $mail->hasTo($employee->email)
                && ($query['employee_no'] ?? null) === $employee->employee_no
                && ($query['registration_code'] ?? null) === $mail->registrationCode;
        });

        $this->assertNotNull($sentCode);

        $code = EmployeeRegistrationCode::query()
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->assertTrue(Hash::check((string) $sentCode, $code->code_hash));
        $this->assertNull($code->used_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'registration_link.sent',
            'entity_type' => 'employee',
            'entity_id' => $employee->id,
        ]);
    }

    public function test_send_registration_link_requires_employee_email(): void
    {
        Mail::fake();

        $admin = $this->createUser();
        $this->grantPermissions($admin, ['registration_codes.manage']);

        $employee = Employee::factory()->create([
            'email' => null,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/employees')
            ->post(route('admin.employees.registration-link.send', $employee->public_id), []);

        $response->assertRedirect('/admin/employees');
        $response->assertSessionHasErrors('employee');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('employee_registration_codes', 0);
    }

    public function test_send_registration_link_fails_when_employee_account_is_already_active(): void
    {
        Mail::fake();

        $admin = $this->createUser();
        $this->grantPermissions($admin, ['registration_codes.manage']);

        $employee = Employee::factory()->create([
            'employee_no' => 'EMP-9990',
            'email' => 'active-employee@example.com',
            'status' => 'active',
        ]);

        User::factory()->create([
            'employee_id' => $employee->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin/employees')
            ->post(route('admin.employees.registration-link.send', $employee->public_id), []);

        $response->assertRedirect('/admin/employees');
        $response->assertSessionHasErrors('employee');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('employee_registration_codes', 0);
    }
}
