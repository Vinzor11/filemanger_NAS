<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    private const UPLOAD_VALIDATION_LIMIT_BYTES = 52428800;

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'public_id' => $user->public_id,
                    'name' => trim(($user->employee?->first_name ?? '').' '.($user->employee?->last_name ?? '')) ?: $user->email,
                    'email' => $user->email,
                    'status' => $user->status,
                    'employee' => $user->employee ? [
                        'public_id' => $user->employee->public_id,
                        'employee_no' => $user->employee->employee_no,
                        'first_name' => $user->employee->first_name,
                        'last_name' => $user->employee->last_name,
                        'department_id' => $user->employee->department_id,
                    ] : null,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'requestId' => $request->attributes->get('request_id'),
            'uploadLimits' => [
                'maxFileBytes' => $this->effectiveMaxUploadBytes(),
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
                'share_link_url' => fn () => $request->session()->get('share_link_url'),
                'share_link_public_id' => fn () => $request->session()->get('share_link_public_id'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    private function effectiveMaxUploadBytes(): int
    {
        $uploadMax = $this->iniSizeToBytes((string) ini_get('upload_max_filesize'));
        $postMax = $this->iniSizeToBytes((string) ini_get('post_max_size'));

        $limits = array_filter([
            self::UPLOAD_VALIDATION_LIMIT_BYTES,
            $uploadMax,
            $postMax,
        ], static fn (int $value): bool => $value > 0);

        return (int) min($limits);
    }

    private function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $numericPart = (float) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => (int) round($numericPart * 1024 * 1024 * 1024),
            'm' => (int) round($numericPart * 1024 * 1024),
            'k' => (int) round($numericPart * 1024),
            default => (int) round($numericPart),
        };
    }
}
