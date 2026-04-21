<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // M3/M4 beyond current scope — domyślnie Laravel nie wstrzykuje nullable
        // opcjonalnych zależności (parametr ma default = null → traktowany jako primitive).
        // Wymuszamy wstrzyknięcie BlockPeriodizationService + PlanMemoryService w
        // TrainingContextService, żeby endpoint /api/weekly-plan realnie korzystał z Etapu B i C.
        $this->app->singleton(\App\Services\TrainingContextService::class, function ($app) {
            return new \App\Services\TrainingContextService(
                $app->make(\App\Services\TrainingSignalsService::class),
                $app->make(\App\Services\UserProfileService::class),
                $app->make(\App\Services\BlockPeriodizationService::class),
                $app->make(\App\Services\PlanMemoryService::class),
            );
        });

        // TrainingAdjustmentsService też ma opcjonalny PlanMemoryService — wymuszamy injection.
        $this->app->singleton(\App\Services\TrainingAdjustmentsService::class, function ($app) {
            return new \App\Services\TrainingAdjustmentsService(
                $app->make(\App\Services\PlanMemoryService::class),
            );
        });

        // TrainingAlertsV1Service — tak samo.
        $this->app->singleton(\App\Services\TrainingAlertsV1Service::class, function ($app) {
            return new \App\Services\TrainingAlertsV1Service(
                $app->make(\App\Services\PlanMemoryService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
