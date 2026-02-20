<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ClaimRegistrationRequest;
use App\Services\RegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClaimRegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrationService,
    ) {
    }

    public function create(Request $request): Response
    {
        return Inertia::render('auth/register-claim', [
            'employee_no' => $request->query('employee_no'),
            'registration_code' => $request->query('registration_code'),
        ]);
    }

    public function store(ClaimRegistrationRequest $request): RedirectResponse
    {
        $this->registrationService->claimEmployee($request->validated(), $request);

        return redirect()
            ->route('auth.register.pending')
            ->with('status', 'Registration submitted. Your account is pending admin approval.');
    }

    public function pending(Request $request): Response
    {
        return Inertia::render('auth/pending-approval', [
            'status' => $request->session()->get('status'),
        ]);
    }
}
