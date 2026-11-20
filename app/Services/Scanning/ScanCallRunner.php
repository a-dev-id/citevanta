<?php

namespace App\Services\Scanning;

use App\Enums\AiCallKind;
use App\Models\Prompt;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Services\Analysis\ResponseAnalyzer;
use App\Services\Budget\AiCallContext;
use App\Services\Budget\BudgetGuard;
use App\Services\Engines\AskContext;
use App\Services\Engines\EngineManager;
use App\Services\Engines\EngineResult;
use App\Services\Engines\OpenAiLimits;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenAI\Exceptions\ErrorException;
use Throwable;

/**
 * One scan call: ask the engine once, save the answer and its citations, then
 * analyse it. Safe to repeat: an answered call is skipped, and an answer that
 * was saved but not analysed (job cut off) is only analysed.
 */
final class ScanCallRunner
{
    public const SKIPPED = 'skipped';

    public const ANSWERED = 'answered';

    public const ANALYZED_ONLY = 'analyzed_only';

    private const MAX_ERRORS = 20;

    public function __construct(
        private readonly EngineManager $engines,
        private readonly ResponseAnalyzer $analyzer,
        private readonly BudgetGuard $guard,
    ) {}

    public function run(ScanRun $scanRun, Prompt $prompt, int $runNumber, string $engineKey): string
    {
        $hotel = $scanRun->hotel;
        $response = $this->existingResponse($scanRun, $prompt, $runNumber, $engineKey);

        if ($response?->analysis()->exists()) {
            return self::SKIPPED;
        }

        $outcome = self::ANALYZED_ONLY;
        $budget = AiCallContext::forScan($scanRun);

        if ($response === null) {
            // Free audits and trials get the cheapest limits; paid and Private plan scans the paid ones.
            $engine = $this->engines->make($engineKey, OpenAiLimits::tierFor($budget->bucket));
            $result = $this->guard->run(
                $budget,
                AiCallKind::Answer,
                fn () => $engine->ask($prompt->text, $this->context($scanRun, $runNumber, (string) $prompt->language)),
                fn (EngineResult $result) => [$engine->model(), $result->tokensIn, $result->tokensOut, $result->webSearchCalls],
            );
            $response = $this->store($scanRun, $prompt, $runNumber, $engineKey, $engine->model(), $result);
            $outcome = self::ANSWERED;
        }

        $competitors = $hotel->competitors()->orderBy('id')->pluck('name')->all();
        $analysisModel = $this->engines->isFake() ? 'fake-engine' : (string) config('citevanta.analysis_model');
        $analysis = $this->guard->run(
            $budget,
            AiCallKind::Analysis,
            fn () => $this->analyzer->analyze($response->answer_text, $hotel->name, $hotel->city, $competitors, (array) ($hotel->alternative_names ?? [])),
            fn ($analysis) => [$analysisModel, $analysis->tokensIn, $analysis->tokensOut],
        );

        $response->analysis()->create($analysis->toAttributes() + [
            'tokens_in' => $analysis->tokensIn,
            'tokens_out' => $analysis->tokensOut,
        ]);

        return $outcome;
    }

    /**
     * Record a call that failed for good: bump the failed count and keep a
     * short, secret-free message (at most MAX_ERRORS are kept).
     */
    public function recordFailure(ScanRun $scanRun, string $label, Throwable $error): void
    {
        $message = $label.': '.self::describe($error);

        DB::transaction(function () use ($scanRun, $message): void {
            $fresh = ScanRun::query()->lockForUpdate()->find($scanRun->id);

            if ($fresh === null) {
                return;
            }

            $fresh->update([
                'failed_count' => $fresh->failed_count + 1,
                'errors' => array_slice([...($fresh->errors ?? []), $message], -self::MAX_ERRORS),
            ]);
        });

        Log::warning('CiteVanta scan call failed', [
            'scan_run_id' => $scanRun->id,
            'call' => $label,
            'exception' => $error::class,
            'message' => Str::limit($error->getMessage(), 300),
        ]);
    }

    /**
     * A short, secret-free description of a failed call. A model name OpenAI
     * does not know gets a clear hint about which setting to fix.
     */
    public static function describe(Throwable $error): string
    {
        if ($error instanceof ErrorException && self::isModelNotFound($error)) {
            $model = preg_match('/model\s+[`\'"]?([\w.:\-\/]+)[`\'"]?/i', $error->getErrorMessage(), $m) ? $m[1] : 'unknown';
            $setting = $model === config('citevanta.analysis_model') && $model !== config('citevanta.scan_model')
                ? 'CITEVANTA_ANALYSIS_MODEL'
                : 'CITEVANTA_SCAN_MODEL';

            return "Model not found: {$model}. Check {$setting}";
        }

        return class_basename($error).' - '.Str::limit($error->getMessage(), 160);
    }

    private static function isModelNotFound(ErrorException $error): bool
    {
        return $error->getErrorCode() === 'model_not_found'
            || ($error->getStatusCode() === 404 && str_contains(strtolower($error->getErrorMessage()), 'model'));
    }

    private function existingResponse(ScanRun $scanRun, Prompt $prompt, int $runNumber, string $engine): ?ScanResponse
    {
        return ScanResponse::query()
            ->where('scan_run_id', $scanRun->id)
            ->where('prompt_id', $prompt->id)
            ->where('run_number', $runNumber)
            ->where('engine', $engine)
            ->first();
    }

    private function store(ScanRun $scanRun, Prompt $prompt, int $runNumber, string $engine, string $model, EngineResult $result): ScanResponse
    {
        try {
            return DB::transaction(function () use ($scanRun, $prompt, $runNumber, $engine, $model, $result): ScanResponse {
                $response = $scanRun->responses()->create([
                    'prompt_id' => $prompt->id,
                    'engine' => $engine,
                    'model' => $model,
                    'run_number' => $runNumber,
                    'answer_text' => $result->text,
                    'tokens_in' => $result->tokensIn,
                    'tokens_out' => $result->tokensOut,
                    'web_search_calls' => $result->webSearchCalls,
                ]);

                foreach ($result->citations as $citation) {
                    $response->citations()->create([
                        'url' => $citation->url,
                        'domain' => Str::limit($citation->domain, 250, ''),
                        'title' => $citation->title !== null ? Str::limit($citation->title, 990) : null,
                    ]);
                }

                return $response;
            });
        } catch (QueryException $e) {
            // Another attempt saved the same call first (unique key): use that answer.
            return $this->existingResponse($scanRun, $prompt, $runNumber, $engine) ?? throw $e;
        }
    }

    private function context(ScanRun $scanRun, int $runNumber, string $language = 'en'): AskContext
    {
        $hotel = $scanRun->hotel;

        return new AskContext(
            hotelName: $hotel->name,
            city: $hotel->city,
            country: $hotel->country,
            hotelWebsite: $hotel->website,
            competitors: $hotel->competitors()->orderBy('id')->pluck('website', 'name')->all(),
            runNumber: $runNumber,
            language: $language,
            alternativeNames: (array) ($hotel->alternative_names ?? []),
        );
    }
}
