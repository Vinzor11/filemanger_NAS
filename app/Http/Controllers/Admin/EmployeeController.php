<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IssueRegistrationCodeRequest;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employeeService,
    ) {
    }

    public function index(Request $request): Response
    {
        $employees = Employee::query()
            ->with(['department', 'position', 'user'])
            ->when($request->string('q')->isNotEmpty(), function ($query) use ($request): void {
                $q = '%'.$request->string('q')->trim().'%';
                $query->where(function ($inner) use ($q): void {
                    $inner->where('employee_no', 'like', $q)
                        ->orWhere('first_name', 'like', $q)
                        ->orWhere('last_name', 'like', $q)
                        ->orWhere('email', 'like', $q);
                });
            })
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->integer('department_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('last_name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/employees/index', [
            'employees' => $employees,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'positions' => Position::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id']),
            'filters' => $request->only(['q', 'department_id', 'status']),
        ]);
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $this->employeeService->create($request->user(), $request->validated(), $request);

        return back()->with('status', 'Employee created.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employeeService->update($request->user(), $employee, $request->validated(), $request);

        return back()->with('status', 'Employee updated.');
    }

    public function sendRegistrationLink(IssueRegistrationCodeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employeeService->sendRegistrationLink(
            actor: $request->user(),
            employee: $employee,
            expiresAt: $request->validated('expires_at'),
            request: $request,
        );

        return back()->with('status', 'Registration link sent to employee email.');
    }
}
