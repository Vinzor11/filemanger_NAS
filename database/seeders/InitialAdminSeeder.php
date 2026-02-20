<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialAdminSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = (string) env('INITIAL_ADMIN_EMAIL', 'admin@gmail.com');
        $password = (string) env('INITIAL_ADMIN_PASSWORD', 'password');

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $department = Department::query()->first();
        if (! $department) {
            return;
        }

        $employee = Employee::query()->create([
            'employee_no' => 'ADMIN-0001',
            'department_id' => $department->id,
            'position_id' => null,
            'position_title' => 'System Administrator',
            'first_name' => 'System',
            'middle_name' => null,
            'last_name' => 'Admin',
            'email' => $email,
            'mobile' => null,
            'status' => 'active',
            'hired_at' => now()->toDateString(),
        ]);

        $user = User::query()->create([
            'employee_id' => $employee->id,
            'email' => $email,
            'password_hash' => Hash::make($password),
            'status' => 'active',
            'approved_at' => now(),
        ]);

        $user->assignRole('SuperAdmin');
    }
}

