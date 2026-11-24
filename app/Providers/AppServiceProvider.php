<?php

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use App\Services\Ai\FakeOpenAiKeyChecker;
use App\Services\Ai\HttpOpenAiKeyChecker;
use App\Services\Ai\OpenAiKeyChecker;
use App\Services\Ai\OpenAiKeyScope;
use App\Services\Ai\ScopedOpenAiClient;
use App\Services\Analysis\AnswerExtractor;
use App\Services\Analysis\FakeAnalyzer;
use App\Services\Analysis\OpenAiAnswerExtractor;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Content\ContentWriter;
use App\Services\Content\FakeContentWriter;
use App\Services\Content\OpenAiContentWriter;
use App\Services\Cron\CronRunner;
use App\Services\Cron\InProcessScheduler;
use App\Services\Engines\EngineContract;
use App\Services\Engines\EngineManager;
use App\Services\Engines\OpenAiLimits;
use App\Services\Google\FakeGoogleApi;
use App\Services\Google\GoogleApi;
use App\Services\Google\HttpGoogleApi;
use App\Services\Insights\Accuracy\AccuracyChecker;
use App\Services\Insights\Accuracy\FakeAccuracyChecker;
use App\Services\Insights\Accuracy\OpenAiAccuracyChecker;
use App\Services\Insights\Competitors\CompetitorReasoner;
use App\Services\Insights\Competitors\FakeCompetitorReasoner;
use App\Services\Insights\Competitors\OpenAiCompetitorReasoner;
use App\Services\Insights\OpenAiJsonCaller;
use App\Services\Prompts\PromptGenerator;
use App\Services\Prompts\TemplatePromptGenerator;
use App\Services\Recommendations\FakeRecommendationWriter;
use App\Services\Recommendations\OpenAiRecommendationWriter;
use App\Services\Recommendations\RecommendationWriter;
use App\Services\Scanning\CostCalculator;
use App\Services\Site\HumanVerifier;
use App\Services\Site\TurnstileVerifier;
use App\Services\Support\ApiRetry;
use App\Services\Trials\TrialGate;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Exceptions\ApiKeyIsMissing;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EngineManager::class);

        // Records its calls, so tests can inspect them (BILLING_PROVIDER=fake).
        $this->app->singleton(FakeBillingProvider::class);

        $this->app->bind(HumanVerifier::class, TurnstileVerifier::class);

        // Google: the real APIs with GOOGLE_SERVICE_ACCOUNT_JSON_PATH, otherwise deterministic fakes.
        $this->app->bind(GoogleApi::class, fn ($app) => config('services.google.fake')
            ? $app->make(FakeGoogleApi::class)
            : $app->make(HttpGoogleApi::class));

        // Every OpenAI call uses the key of OpenAiKeyScope: a team's own key
        // inside its guarded calls (BudgetGuard), otherwise ours (built lazily).
        $this->app->singleton(OpenAiKeyScope::class);
        // The package provider is deferred: load it now, so its binding cannot replace ours later.
        $this->app->register(OpenAI\Laravel\ServiceProvider::class);
        $this->app->singleton(ClientContract::class, fn ($app) => new ScopedOpenAiClient(
            $app->make(OpenAiKeyScope::class),
            function (): ClientContract {
                $apiKey = config('openai.api_key');

                if (! is_string($apiKey) || $apiKey === '') {
                    throw ApiKeyIsMissing::create();
                }

                $factory = OpenAI::factory()
                    ->withApiKey($apiKey)
                    ->withHttpClient(new Client(['timeout' => config('openai.request_timeout', 30)]));

                if (is_string(config('openai.organization'))) {
                    $factory->withOrganization(config('openai.organization'));
                }

                if (is_string(config('openai.project'))) {
                    $factory->withProject(config('openai.project'));
                }

                if (is_string(config('openai.base_uri'))) {
                    $factory->withBaseUri(config('openai.base_uri'));
                }

                return $factory->make();
            },
        ));
        $this->app->bind(OpenAiKeyChecker::class, fn ($app) => $app->make(EngineManager::class)->isFake()
            ? new FakeOpenAiKeyChecker
            : new HttpOpenAiKeyChecker);

        $this->app->bind(EngineContract::class, fn ($app) => $app->make(EngineManager::class)->make('openai'));

        $this->app->bind(PromptGenerator::class, TemplatePromptGenerator::class);

        $this->app->bind(AnswerExtractor::class, fn ($app) => $app->make(EngineManager::class)->isFake()
            ? $app->make(FakeAnalyzer::class)
            : new OpenAiAnswerExtractor(
                client: $app->make(ClientContract::class),
                model: (string) config('citevanta.analysis_model'),
                limits: OpenAiLimits::analysis(),
            ));

        $this->app->bind(OpenAiJsonCaller::class, fn ($app) => new OpenAiJsonCaller(
            client: $app->make(ClientContract::class),
            model: (string) config('citevanta.analysis_model'),
        ));

        $this->app->bind(AccuracyChecker::class, fn ($app) => $app->make(EngineManager::class)->isFake()
            ? $app->make(FakeAccuracyChecker::class)
            : $app->make(OpenAiAccuracyChecker::class));

        $this->app->bind(CompetitorReasoner::class, fn ($app) => $app->make(EngineManager::class)->isFake()
            ? $app->make(FakeCompetitorReasoner::class)
            : $app->make(OpenAiCompetitorReasoner::class));

        $this->app->bind(RecommendationWriter::class, fn ($app) => $app->make(EngineManager::class)->isFake()
            ? $app->make(FakeRecommendationWriter::class)
            : $app->make(OpenAiRecommendationWriter::class));

        $this->app->bind(ContentWriter::class, fn ($app) => $app->make(EngineManager::class)->isFake()
            ? $app->make(FakeContentWriter::class)
            : $app->make(OpenAiContentWriter::class));

        $this->app->bind(ApiRetry::class, fn () => ApiRetry::fromConfig());
        $this->app->bind(CostCalculator::class, fn () => CostCalculator::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Paginator::useBootstrapFive();

        // Production runs on HTTPS: generate https:// links even behind a proxy.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // ScanPromptJob: API requests per minute, per engine.
        RateLimiter::for('citevanta-engine', fn (object $job) => Limit::perMinute(
            (int) config("citevanta.rate_limits.{$job->engine}", 30),
        )->by('engine:'.$job->engine));

        // Keep scheduled-task last-run times when schedule:run (process mode) runs them.
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => app(InProcessScheduler::class)
            ->recordFinished($event->task->command));

        // Count processed jobs for the cron run result.
        Event::listen(JobProcessed::class, fn () => CronRunner::countProcessedJob());

        // A trial owner verified their email: start the setup scan that was waiting for it.
        Event::listen(Verified::class, fn (Verified $event) => $event->user instanceof User
            ? $event->user->teams()->get()->each(fn (Team $team) => app(TrialGate::class)->releaseWaiting($team))
            : null);

        // Fallback URL cron: at most 6 calls a minute per IP.
        RateLimiter::for('cron', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));

        // Google API jobs, well under GA4 and Search Console quotas.
        RateLimiter::for('google-api', fn () => Limit::perMinute((int) config('citevanta.google.requests_per_minute', 30))->by('google'));
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
            : null,
        );
    }
}
