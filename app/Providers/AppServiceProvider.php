<?php

namespace App\Providers;

use App\Policies\WorkspaceRecordPolicy;
use App\Services\CurrentWorkspace;
use App\Services\PaidExecutionGate;
use App\Services\TenantContext;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(PaidExecutionGate::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::if('platform', fn () => auth()->check() && auth()->user()->is_platform_admin);
        config(['app.name' => config('branding.name')]);
        Paginator::useBootstrapFive();
        foreach (['PilotResultReview', 'SearchPrompt', 'MarketScenario', 'Hotel', 'TestRun', 'AiResponse', 'Citation', 'HotelMention', 'HotelAssessment', 'TestBatch', 'BatchItem', 'ApiUsage', 'CostEntry', 'Improvement', 'ImprovementEvent', 'ImprovementExplanation'] as $model) {
            Gate::policy('App\\Models\\'.$model, WorkspaceRecordPolicy::class);
        }
        View::composer('*', function ($view) {
            $view->with('workspace', app(CurrentWorkspace::class));
        });
    }
}
