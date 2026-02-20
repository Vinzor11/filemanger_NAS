<?php

namespace App\Providers;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use App\Policies\FilePolicy;
use App\Policies\FolderPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        File::class => FilePolicy::class,
        Folder::class => FolderPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasRole('SuperAdmin') ? true : null;
        });
    }
}

