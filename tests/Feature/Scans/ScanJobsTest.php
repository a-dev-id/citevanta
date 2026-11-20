<?php

namespace Tests\Feature\Scans;

use App\Enums\ScanStatus;
use App\Enums\ScanTrigger;
use App\Jobs\ScanHotelJob;
use App\Jobs\ScanPromptJob;
use App\Models\ScanResponse;
use App\Models\ScanRun;
use App\Services\Engines\EngineManager;
use App\Services\Engines\FakeEngine;
use App\Services\Scanning\ScanLauncher;
use App\Services\Scanning\ScanMaintenance;
use App\Services\Scanning\ScanPlanner;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use OpenAI\Exceptions\ErrorException;
use RuntimeException;
use Tests\Concerns\BuildsScans;
use Tests\Fakes\StubEngine;
use Tests\TestCase;

class ScanJobsTest extends TestCase
{
    use BuildsScans, RefreshDatabase;

    private function queuedRun($hotel): ScanRun
    {
        Queue::fake();

        return app(ScanLauncher::class)->queue($hotel, ScanTrigger::Manual);
    }

    public function test_scan_hotel_job_batches_one_job_per_prompt_run_and_engine(): void
    {
        $hotel = $this->scannableHotel(prompts: 3); // Starter: 2 runs, engine openai
        $hotel->prompts()->create(['text' => 'An inactive prompt that must not be scanned', 'persona' => 'family', 'language' => 'en', 'is_active' => false]);
        $run = $this->queuedRun($hotel);
        Bus::fake();

        (new ScanHotelJob($run))->handle(app(ScanPlanner::class));

        Bus::assertBatched(function (PendingBatch $batch) {
            $calls = collect($batch->jobs)->map(fn (ScanPromptJob $job) => "{$job->promptId}:{$job->runNumber}:{$job->engine}");

            return $batch->jobs->count() === 6
                && $calls->unique()->count() === 6
                && $batch->allowsFailures()
                && $batch->queue() === 'scans'
                && $batch->finallyCallbacks() !== [];
        });

        $run->refresh();
        $this->assertSame(ScanStatus::Running, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->batch_id);
        $this->assertSame(['openai'], $run->engineList());
        $this->assertSame(2, $run->runs_per_prompt);
        $this->assertCount(3, $run->prompt_ids);
        $this->assertSame(6, $run->plannedCalls());
    }

    public function test_scan_hotel_job_is_unique_per_hotel(): void
    {
        $hotel = $this->scannableHotel();
        Queue::fake();

        ScanHotelJob::dispatch($hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'x', 'status' => ScanStatus::Queued, 'trigger' => ScanTrigger::Manual]));
        ScanHotelJob::dispatch($hotel->scanRuns()->create(['engine' => 'openai', 'model' => 'x', 'status' => ScanStatus::Queued, 'trigger' => ScanTrigger::Manual]));

        Queue::assertPushed(ScanHotelJob::class, 1);
        $this->assertSame('hotel-'.$hotel->id, (new ScanHotelJob($hotel->scanRuns()->first()))->uniqueId());
    }

    public function test_a_hotel_without_active_prompts_finishes_as_failed(): void
    {
        $hotel = $this->scannableHotel(prompts: 1);
        $hotel->prompts()->update(['is_active' => false]);
        $run = $this->queuedRun($hotel);

        (new ScanHotelJob($run))->handle(app(ScanPlanner::class));

        $this->assertSame(ScanStatus::Failed, $run->fresh()->status);
    }

    private function promptJob(ScanRun $run, int $runNumber = 1): ScanPromptJob
    {
        return new ScanPromptJob($run->id, $run->hotel->prompts()->first()->id, $runNumber, 'openai');
    }

    private function stub(\Closure $answer): StubEngine
    {
        $stub = new StubEngine($answer);
        app(EngineManager::class)->swap('openai', $stub);

        return $stub;
    }

    public function test_scan_prompt_job_is_idempotent(): void
    {
        $run = $this->queuedRun($this->scannableHotel());
        $run->update(['prompt_ids' => [$run->hotel->prompts()->first()->id], 'runs_per_prompt' => 1]);
        $stub = $this->stub(fn () => "1. Alaya Resort Ubud - great\n2. Maya Ubud Resort - fine");
        $job = $this->promptJob($run);

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertCount(1, $stub->prompts, 'the engine is asked once');
        $this->assertSame(1, ScanResponse::count());
        $this->assertNotNull(ScanResponse::sole()->analysis);
    }

    public function test_an_answer_saved_without_analysis_is_only_analysed_on_retry(): void
    {
        $run = $this->queuedRun($this->scannableHotel());
        $prompt = $run->hotel->prompts()->first();
        $run->responses()->create(['prompt_id' => $prompt->id, 'engine' => 'openai', 'model' => 'm', 'run_number' => 1, 'answer_text' => '1. Alaya Resort Ubud - ok']);
        $stub = $this->stub(fn () => throw new RuntimeException('must not be called'));

        app()->call([$this->promptJob($run), 'handle']);

        $this->assertCount(0, $stub->prompts);
        $this->assertNotNull(ScanResponse::sole()->analysis);
    }

    public function test_auth_and_bad_request_errors_are_recorded_at_once_without_retry(): void
    {
        $run = $this->queuedRun($this->scannableHotel());
        $this->stub(fn () => throw new ErrorException(['message' => 'Incorrect API key', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key'], new Psr7Response(401)));

        app()->call([$this->promptJob($run), 'handle']); // no exception: the batch is not failed

        $run->refresh();
        $this->assertSame(1, $run->failed_count);
        $this->assertStringContainsString('Incorrect API key', $run->errors[0]);
        $this->assertSame(0, ScanResponse::count());
    }

    public function test_transient_errors_are_rethrown_for_the_queue_and_recorded_once_at_the_end(): void
    {
        $run = $this->queuedRun($this->scannableHotel());
        $this->stub(fn () => throw new RuntimeException('connection reset'));
        $job = $this->promptJob($run);

        try {
            app()->call([$job, 'handle']);
            $this->fail('A retryable error must be thrown so the queue retries it.');
        } catch (RuntimeException $e) {
            $this->assertSame('connection reset', $e->getMessage());
        }

        $this->assertSame(0, $run->fresh()->failed_count, 'not recorded while retries remain');
        $this->assertSame(3, $job->maxExceptions);
        $this->assertSame([2, 6], $job->backoff);

        $job->failed($e); // what the queue calls after the last attempt
        $this->assertSame(1, $run->fresh()->failed_count);
    }

    public function test_a_call_cut_off_three_times_stops_and_is_recorded(): void
    {
        $run = $this->queuedRun($this->scannableHotel());
        $stub = $this->stub(fn () => 'never reached');
        $job = $this->promptJob($run);

        // Three earlier starts never reached "finally" (killed by a time limit).
        Cache::put($job->startsKey(), 3, now()->addDay());

        app()->call([$job, 'handle']);

        $this->assertCount(0, $stub->prompts);
        $this->assertSame(1, $run->fresh()->failed_count);
        $this->assertStringContainsString('Timed out 3 times', $run->fresh()->errors[0]);
        $this->assertLessThanOrEqual(10, $job->tries, 'bounded: never retries forever');
    }

    public function test_a_finished_attempt_does_not_count_as_a_cut_off(): void
    {
        $run = $this->queuedRun($this->scannableHotel());
        $this->stub(fn () => throw new RuntimeException('flaky'));
        $job = $this->promptJob($run);

        foreach (range(1, 5) as $attempt) {
            try {
                app()->call([$job, 'handle']);
            } catch (RuntimeException) {
                // the queue would retry
            }
        }

        $this->assertSame(0, (int) Cache::get($job->startsKey()));
        $this->assertSame(0, $run->fresh()->failed_count);
    }

    public function test_admin_retry_dispatches_only_missing_calls(): void
    {
        $hotel = $this->scannableHotel(prompts: 2);
        $run = $this->queuedRun($hotel);
        [$first, $second] = $hotel->prompts()->orderBy('id')->get();
        $run->update(['prompt_ids' => [$first->id, $second->id], 'runs_per_prompt' => 2, 'status' => ScanStatus::CompletedWithErrors]);

        // 3 of 4 calls answered (one of them without analysis), 1 never answered.
        $this->stub(fn () => '1. Maya Ubud Resort - fine');
        foreach ([[$first, 1], [$first, 2], [$second, 1]] as [$prompt, $runNumber]) {
            app()->call([new ScanPromptJob($run->id, $prompt->id, $runNumber, 'openai'), 'handle']);
        }
        ScanResponse::where('prompt_id', $second->id)->first()->analysis()->delete();

        Bus::fake();
        $queued = app(ScanMaintenance::class)->retryMissing($run->fresh());

        $this->assertSame(2, $queued);
        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->map(fn ($job) => "{$job->promptId}:{$job->runNumber}")->sort()->values()->all()
            === ["{$second->id}:1", "{$second->id}:2"]);
        $this->assertSame(ScanStatus::Running, $run->fresh()->status);
    }

    public function test_retry_is_refused_while_the_scan_is_still_running(): void
    {
        $run = $this->queuedRun($this->scannableHotel());

        $this->expectException(RuntimeException::class);
        app(ScanMaintenance::class)->retryMissing($run);
    }

    public function test_the_fake_engine_is_used_by_default(): void
    {
        $this->assertInstanceOf(FakeEngine::class, app(EngineManager::class)->make('openai'));
    }
}
