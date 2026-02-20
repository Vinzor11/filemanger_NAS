<?php

namespace App\Providers;

use App\Contracts\AntivirusScanner;
use App\Services\Antivirus\ClamAvScanner;
use App\Services\Antivirus\NullAntivirusScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AntivirusScanner::class, function (): AntivirusScanner {
            $enabled = (bool) config('antivirus.enabled', true);
            $driver = (string) config('antivirus.driver', 'clamav');

            if (! $enabled || $driver === 'null') {
                return new NullAntivirusScanner();
            }

            return new ClamAvScanner(
                host: (string) config('antivirus.clamav.host', '127.0.0.1'),
                port: (int) config('antivirus.clamav.port', 3310),
                timeoutSeconds: (int) config('antivirus.clamav.timeout', 10),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
