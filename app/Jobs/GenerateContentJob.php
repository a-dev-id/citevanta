<?php

namespace App\Jobs;

use App\Enums\ContentStatus;
use App\Models\ContentPiece;
use App\Services\Budget\BudgetExceeded;
use App\Services\Content\ContentService;
use App\Services\Engines\EngineManager;
use App\Services\Support\ApiRetry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/**
 * Exactly one AI call: write one pending content piece (ContentService).
 * A piece that is no longer pending is left alone, so a retry never pays twice.
 */
class GenerateContentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [5, 20];

    public int $timeout;

    public function __construct(public int $contentPieceId)
    {
        $this->timeout = (int) config('citevanta.job_timeout', 70);
        $this->onQueue('scans');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        if ($this->job === null || app(EngineManager::class)->isFake()) {
            return [];
        }

        return [new RateLimited('citevanta-engine')];
    }

    public function handle(ContentService $content): void
    {
        $piece = ContentPiece::with(['hotel', 'recommendation'])->find($this->contentPieceId);

        if ($piece === null || $piece->status !== ContentStatus::Pending) {
            return;
        }

        try {
            $content->generate($piece);
        } catch (BudgetExceeded) {
            $content->fail($piece, 'Content generation is paused for a while because of high demand. Please try again later.');
        } catch (Throwable $e) {
            if (! ApiRetry::isRetryable($e)) {
                $content->fail($piece, class_basename($e).': '.$e->getMessage());

                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $piece = ContentPiece::find($this->contentPieceId);

        if ($piece !== null && $piece->status === ContentStatus::Pending) {
            app(ContentService::class)->fail($piece, $exception?->getMessage() ?? 'Failed after retries.');
        }
    }
}
