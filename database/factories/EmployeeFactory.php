<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_no' => strtoupper(fake()->unique()->bothify('EMP####')),
            'department_id' => Department::factory(),
            'position_id' => null,
            'position_title' => fake()->jobTitle(),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'email' => fake()->optional()->safeEmail(),
            'mobile' => fake()->optional()->phoneNumber(),
            'status' => 'active',
            'hired_at' => fake()->optional()->date(),
        ];
    }
}

