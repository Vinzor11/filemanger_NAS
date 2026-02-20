<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApproveUserRequest;
use App\Http\Requests\Admin\RejectUserRequest;
use App\Models\User;
use App\Services\UserApprovalService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class PendingApprovalController extends Controller
{
    public function __construct(
        private readonly UserApprovalService $userApprovalService,
    ) {
    }

    public function index(): Response
    {
        $pendingUsers = User::query()
            ->with(['employee.department'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->paginate(20);

        $roles = Role::query()->orderBy('name')->pluck('name');

        return Inertia::render('admin/pending-approvals/index', [
            'pendingUsers' => $pendingUsers,
            'roles' => $roles,
        ]);
    }

    public function approve(ApproveUserRequest $request, User $user): RedirectResponse
    {
        $this->userApprovalService->approve(
            actor: $request->user(),
            target: $user,
            roles: $request->validated('roles'),
            request: $request,
        );

        return back()->with('status', 'User approved successfully.');
    }

    public function reject(RejectUserRequest $request, User $user): RedirectResponse
    {
        $this->userApprovalService->reject(
            actor: $request->user(),
            target: $user,
            reason: $request->validated('rejection_reason'),
            request: $request,
        );

        return back()->with('status', 'User rejected.');
    }
}

