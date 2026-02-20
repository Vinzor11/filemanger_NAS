<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Configure custom login workflow.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $identifier = trim((string) $request->input('login'));
            $password = (string) $request->input('password');

            $user = User::query()
                ->with('employee')
                ->where(function ($query) use ($identifier): void {
                    $query->where('email', $identifier)
                        ->orWhereHas('employee', fn ($employeeQuery) => $employeeQuery->where('employee_no', $identifier));
                })
                ->first();

            if (! $user || ! $this->verifyUserPassword($user, $password)) {
                return null;
            }

            if ($user->status !== 'active') {
                $message = match ($user->status) {
                    'pending' => 'Your account is pending approval.',
                    'rejected' => 'Your account was rejected by admin.',
                    'blocked' => 'Your account is blocked.',
                    default => 'Your account is not allowed to sign in.',
                };

                throw ValidationException::withMessages([
                    'login' => $message,
                ]);
            }

            if ($user->employee?->status !== 'active') {
                throw ValidationException::withMessages([
                    'login' => 'Your employee record is inactive.',
                ]);
            }

            $user->forceFill([
                'last_login_at' => now(),
            ])->save();

            return $user;
        });
    }

    private function verifyUserPassword(User $user, string $password): bool
    {
        $stored = $user->password_hash;

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        $isHashed = Hash::isHashed($stored);
        $plaintextMatch = ! $isHashed && hash_equals($stored, $password);
        $hashedMatch = $isHashed && password_verify($password, $stored);

        if (! $plaintextMatch && ! $hashedMatch) {
            return false;
        }

        if ($plaintextMatch || ($isHashed && Hash::needsRehash($stored))) {
            $user->forceFill([
                'password_hash' => $password,
            ])->save();
        }

        return true;
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => true,
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register-claim'));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input('login')).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('register', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('download', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('share-link', fn (Request $request): Limit => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('share-download', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));
    }
}
