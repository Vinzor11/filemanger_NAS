<?php

namespace Tests\Feature\Admin;

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class EmployeeCreateIdempotencyTest extends TestCase
{
    use BuildsDomainData;
    use RefreshDatabase;

    public function test_employee_create_is_processed_once_for_same_idempotency_key(): void
    {
        $admin = $this->createUser();
        $this->grantPermissions($admin, ['employees.manage']);

        $department = $admin->employee->department;

        $payload = [
            'employee_no' => 'EMP-IDEMP-0001',
            'department_id' => $department->id,
            'position_id' => null,
            'position_title' => 'Records Assistant',
            'first_name' => 'Nina',
            'middle_name' => 'M',
            'last_name' => 'Garcia',
            'email' => 'nina.garcia@example.com',
            'mobile' => '+1 555 300 1020',
            'status' => 'active',
            'hired_at' => now()->toDateString(),
        ];

        $first = $this->actingAs($admin)
            ->from(route('admin.employees.index'))
            ->withHeader('X-Idempotency-Key', 'create-employee-1')
            ->post(route('admin.employees.store'), $payload);

        $first->assertRedirect(route('admin.employees.index'));

        $replay = $this->actingAs($admin)
            ->from(route('admin.employees.index'))
            ->withHeader('X-Idempotency-Key', 'create-employee-1')
            ->post(route('admin.employees.store'), $payload);

        $replay->assertRedirect(route('admin.employees.index'));

        $this->assertSame(1, \App\Models\Employee::query()->where('employee_no', 'EMP-IDEMP-0001')->count());
        $this->assertDatabaseHas('idempotency_keys', [
            'actor_user_id' => $admin->id,
            'scope' => 'create-employee',
            'idempotency_key' => 'create-employee-1',
            'status' => 'completed',
        ]);
        $this->assertSame(1, IdempotencyKey::query()->count());
    }

    public function test_employee_create_requires_idempotency_key_header(): void
    {
        $admin = $this->createUser();
        $this->grantPermissions($admin, ['employees.manage']);

        $department = $admin->employee->department;

        $response = $this->actingAs($admin)->post(route('admin.employees.store'), [
            'employee_no' => 'EMP-NO-KEY',
            'department_id' => $department->id,
            'position_id' => null,
            'position_title' => 'Assistant',
            'first_name' => 'Liza',
            'middle_name' => null,
            'last_name' => 'Tan',
            'email' => null,
            'mobile' => null,
            'status' => 'active',
            'hired_at' => now()->toDateString(),
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Missing X-Idempotency-Key header.',
            ]);
    }
}
